<?php

namespace Modules\LJPcCalendarModule\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\ConfirmsDeletions;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\OAuthApi;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;

/**
 * Pulls external calendars into FreeScout, run by calendar:sync every minute (never through the queue). Each due
 * calendar is synced under a lease, until the run's time budget is used; an unfinished pull continues in the next run.
 * The run renews the lease while it works, and stops when another run took the calendar over after it ran out.
 *
 * Full listings are swept by generation: a listing raises the calendar's generation, every event it mentions gets that
 * generation, and once it completes the events with an older one are deleted; a provider that confirms deletions
 * (ConfirmsDeletions) is asked first, and only what it says is gone is deleted. The engine keeps its own state in
 * calendars.sync_state under keys starting with "_", which providers never see.
 */
class SyncService
{
    public const LEASE_SECONDS = 120;
    public const RUN_SECONDS = 40;
    public const MAX_BACKOFF_INTERVALS = 6;
    /** The lease is renewed before every round and after this many upserts, so a long import keeps it. */
    public const LEASE_RENEW_UPSERTS = 100;
    /** "Sync now" leaves a calendar that completed a sync this recently alone, so clicks never spend a provider's quota. */
    public const RECENT_SECONDS = 30;
    /** How long "Sync now" may sync while someone waits; a listing that needs longer goes on in the next runs. */
    public const BY_HAND_SECONDS = 20;
    /** Columns a pull writes on every event it mentions; a change to only these is no change anyone sees. */
    private const BOOKKEEPING = ['sync_generation', 'updated_at', 'etag', 'remote_href'];

    private ProviderRegistry $providers;

    private EventImporter $importer;

    private HttpClientFactory $http;

    /** ['id' => calendar id, 'until' => UTC end] while runDue() holds a lease; null otherwise. */
    private ?array $lease = null;

    /** What a sync by hand added, changed and removed, and whether its pull completed; null otherwise. */
    private ?array $tally = null;

    public function __construct(ProviderRegistry $providers, EventImporter $importer, HttpClientFactory $http)
    {
        $this->providers = $providers;
        $this->importer = $importer;
        $this->http = $http;
    }

    /** Syncs due calendars (or the one given) until the budget is used. Returns ['synced' => int, 'failed' => int, 'skipped' => int]. */
    public function runDue(SyncBudget $budget, ?int $calendarId = null, bool $force = false): array
    {
        $counts = ['synced' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->candidates($calendarId, $force) as $candidate) {
            if ($budget->exhausted()) {
                break;
            }
            $until = $this->takeLease($candidate->id);
            if ($until === null) {
                $counts['skipped']++;
                continue;
            }
            $this->lease = ['id' => $candidate->id, 'until' => $until];
            try {
                // Read again under the lease: another run may have saved a newer sync state in the meantime.
                $calendar = Calendar::find($candidate->id);
                if ($calendar === null) {
                    continue;
                }
                $this->syncCalendar($calendar, $budget);
                if ($this->lease === null) {
                    $counts['skipped']++; // the lease was lost; the run that holds it now records the result
                } else {
                    $counts[$calendar->sync_failures > 0 ? 'failed' : 'synced']++;
                }
            } finally {
                $this->releaseLease();
            }
        }

        return $counts;
    }

    /** One calendar, lease already held. Never throws for provider errors: they land in last_error and the backoff. */
    public function syncCalendar(Calendar $calendar, SyncBudget $budget): void
    {
        try {
            $provider = $this->providers->for($calendar);
            do {
                $state = is_array($calendar->sync_state) ? $calendar->sync_state : [];
                $changes = $provider->pull($calendar, self::withoutEngineKeys($state), $budget);
                $this->keepLease(); // before anything of this round is written
                $this->apply($calendar, $changes, $state, $provider, $budget);
            } while (!$changes->complete && !$budget->exhausted());
            if ($this->tally !== null) {
                $this->tally['complete'] = $changes->complete;
            }
            $this->keepLease(); // the last round may have taken long: only the lease holder records the result
            $this->succeeded($calendar, $changes->complete);
        } catch (SyncLeaseLost $e) {
            $this->leaseLost($calendar);
        } catch (ProviderException $e) {
            if (!$this->ownsLease()) {
                $this->leaseLost($calendar); // a request that outlived the lease: the run holding it now records the result

                return;
            }
            $error = $e->userMessage();
            $status = $e->httpStatus();
            if ($status !== null && strpos($error, '(HTTP ' . $status . ')') === false) {
                $error .= ' (HTTP ' . $status . ')';
            }
            $this->failed($calendar, $error, $e instanceof ConnectionNeedsReauth);
        } catch (\Throwable $e) {
            report($e);
            if (!$this->ownsLease()) {
                $this->leaseLost($calendar);

                return;
            }
            $this->failed($calendar, __('Syncing failed because of an unexpected error. See the FreeScout log.'), false);
        }
    }

    /**
     * Syncs this one calendar right away within the budget, as a run of calendar:sync would, unless a run holds it.
     * Someone waits for it, so an API waits out throttling only briefly (OAuthApi::INTERACTIVE_MAX_WAIT_SECONDS), and a
     * full listing that has not finished is left to the next run: it may take many rounds. Returns false when nothing
     * synced: a run holds the lease, the listing is unfinished, or the calendar doesn't sync (disabled, a FreeScout
     * calendar, a connection that is not ok). A sync that fails counts as synced; its error is in last_error.
     */
    public function syncNow(Calendar $calendar, SyncBudget $budget): bool
    {
        $current = Calendar::find($calendar->id);
        if ($current === null || !empty($current->sync_state['_listing'])) {
            return false;
        }
        $counts = $this->http->waitingAtMost(OAuthApi::INTERACTIVE_MAX_WAIT_SECONDS, function () use ($budget, $calendar) {
            return $this->runDue($budget, $calendar->id, true);
        });

        return $counts['synced'] + $counts['failed'] > 0;
    }

    /**
     * "Sync now" in the settings and on the calendar page: this calendar right away within the budget, also going on
     * with a listing an earlier run left unfinished. Returns ['state', 'created', 'updated', 'deleted']; the state is
     * synced, partial (the budget ended first, the next run goes on), running (a run of calendar:sync holds it now),
     * recent (it completed a sync less than RECENT_SECONDS ago and is left alone), failed (the error is in last_error),
     * off (the calendar is switched off) or reauth (its account must be connected again).
     */
    public function syncByHand(Calendar $calendar, SyncBudget $budget): array
    {
        $result = ['state' => 'recent', 'created' => 0, 'updated' => 0, 'deleted' => 0];
        $current = Calendar::find($calendar->id);
        if ($current === null) {
            return ['state' => 'running'] + $result;
        }
        if (!$current->enabled) {
            return ['state' => 'off'] + $result;
        }
        $connection = $current->calendarConnection;
        if ($current->usesConnection() && (!$connection || $connection->status !== CalendarConnection::STATUS_OK)) {
            return ['state' => 'reauth'] + $result;
        }
        $synced = $current->last_synced_at ? (new DateTimeImmutable($current->last_synced_at, new DateTimeZone('UTC')))->getTimestamp() : null;
        if ($synced !== null && $synced > time() - self::RECENT_SECONDS && empty($current->sync_state['_listing']) && trim((string) $current->last_error) === '') {
            return $result;
        }
        $this->tally = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'complete' => true];
        try {
            $counts = $this->http->waitingAtMost(OAuthApi::INTERACTIVE_MAX_WAIT_SECONDS, function () use ($budget, $calendar) {
                return $this->runDue($budget, $calendar->id, true);
            });
            $tally = $this->tally;
        } finally {
            $this->tally = null;
        }
        if ($counts['synced'] + $counts['failed'] === 0) {
            return ['state' => 'running'] + $result;
        }
        $state = $counts['failed'] > 0 ? 'failed' : ($tally['complete'] ? 'synced' : 'partial');

        return ['state' => $state, 'created' => $tally['created'], 'updated' => $tally['updated'], 'deleted' => $tally['deleted']];
    }

    /** Plans a sync for the next run of calendar:sync: next_sync_at = now, failures kept. */
    public function requestSync(Calendar $calendar): void
    {
        $calendar->next_sync_at = gmdate('Y-m-d H:i:s');
        $calendar->save();
    }

    /**
     * Lists the calendar in full at its next sync, which runs within a minute. The provider's own state (tokens, page
     * cursors) is dropped from the row as it is now, and an unfinished listing is ended, so the forced listing starts a
     * generation of its own and sweeps what the unfinished one stamped and the provider no longer has.
     */
    public function relist(Calendar $calendar): void
    {
        $current = Calendar::find($calendar->id);
        if ($current === null) {
            return;
        }
        $state = is_array($current->sync_state) ? $current->sync_state : [];
        $state = array_diff_key($state, self::withoutEngineKeys($state));
        $state['_listing'] = false;
        $state['_seen'] = 0;
        $state['_listing_started'] = null;
        $current->sync_state = $state;
        $current->next_sync_at = gmdate('Y-m-d H:i:s');
        $current->save();
    }

    /** Minutes until the next attempt after this many failures in a row: the interval, doubled per failure, at most six intervals. */
    public static function backoffMinutes(int $interval, int $failures): int
    {
        $interval = max(1, $interval);

        return min($interval * 2 ** min(max(0, $failures - 1), 16), $interval * self::MAX_BACKOFF_INTERVALS);
    }

    /** Enabled external calendars whose connection (if any) works, due ones only unless forced; never synced ones first. */
    private function candidates(?int $calendarId, bool $force): Collection
    {
        $query = Calendar::where('enabled', true)
            ->where('provider', '!=', Calendar::PROVIDER_LOCAL)
            ->where(function ($query) {
                $query->whereNull('connection_id')->orWhereHas('calendarConnection', function ($connection) {
                    $connection->where('status', CalendarConnection::STATUS_OK);
                });
            });
        if ($calendarId !== null) {
            $query->where('id', $calendarId);
        }
        if (!$force) {
            $now = gmdate('Y-m-d H:i:s');
            $query->where(function ($query) use ($now) {
                $query->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', $now);
            });
        }

        // Sorted here, because databases disagree on where NULL sorts.
        return $query->get()->sortBy(function (Calendar $calendar) {
            return (string) $calendar->next_sync_at;
        })->values();
    }

    /** Atomic: only one run gets the calendar until the lease ends or is released. Returns the lease's end, or null. */
    private function takeLease(int $calendarId): ?string
    {
        $now = gmdate('Y-m-d H:i:s');
        $until = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        $taken = DB::table('calendars')
            ->where('id', $calendarId)
            ->where(function ($query) use ($now) {
                $query->whereNull('sync_lease_until')->orWhere('sync_lease_until', '<', $now);
            })
            ->update(['sync_lease_until' => $until]);

        return $taken === 1 ? $until : null;
    }

    /** Extends this run's lease, and stops the run when another run took the calendar after the lease ran out. */
    private function keepLease(): void
    {
        if ($this->lease === null) {
            return; // syncCalendar() called directly, without a lease
        }
        $until = gmdate('Y-m-d H:i:s', time() + self::LEASE_SECONDS);
        $renewed = DB::table('calendars')
            ->where('id', $this->lease['id'])
            ->where('sync_lease_until', $this->lease['until'])
            ->update(['sync_lease_until' => $until]);
        // MySQL counts changed rows only, so renewing within the same second changes nothing and counts 0.
        $ours = $renewed === 1 || ($until === $this->lease['until']
            && DB::table('calendars')->where('id', $this->lease['id'])->value('sync_lease_until') === $until);
        if (!$ours) {
            $calendarId = $this->lease['id'];
            $this->lease = null;
            throw new SyncLeaseLost('Lost the sync lease of calendar ' . $calendarId);
        }
        $this->lease['until'] = $until;
    }

    /** Whether this run may record its result; always true without a lease (syncCalendar() called directly). */
    private function ownsLease(): bool
    {
        return $this->lease === null
            || DB::table('calendars')->where('id', $this->lease['id'])->value('sync_lease_until') === $this->lease['until'];
    }

    private function leaseLost(Calendar $calendar): void
    {
        $this->lease = null; // runDue() counts the calendar as skipped
        Log::warning('A calendar sync outlived its lease and stopped; another run continues it', ['calendar_id' => $calendar->id]);
    }

    /** Only the run that still holds the lease releases it. */
    private function releaseLease(): void
    {
        if ($this->lease !== null) {
            DB::table('calendars')
                ->where('id', $this->lease['id'])
                ->where('sync_lease_until', $this->lease['until'])
                ->update(['sync_lease_until' => null]);
        }
        $this->lease = null;
    }

    /** Applies one round and saves the state right away, so a later failure or a spent budget resumes from here. */
    private function apply(Calendar $calendar, ChangeSet $changes, array $state, CalendarProvider $provider, SyncBudget $budget): void
    {
        $generation = (int) ($state['_generation'] ?? 0);
        $listing = (bool) ($state['_listing'] ?? false);
        $seen = (int) ($state['_seen'] ?? 0);
        $started = $state['_listing_started'] ?? null;
        $emptyListings = (int) ($state['_empty_listings'] ?? 0);
        if ($changes->fullListing && !$listing) {
            $generation++;
            $listing = true;
            $seen = 0;
            // A Unix time: the sweep turns it into the app's wall-clock time, which updated_at holds (sweepCutoff()).
            $started = time();
        }

        $keep = $changes->unchanged;
        $count = 0;
        $failures = 0;
        foreach ($changes->upserts as $remote) {
            if ($count > 0 && $count % self::LEASE_RENEW_UPSERTS === 0) {
                $this->keepLease();
            }
            $count++;
            try {
                $event = $this->importer->upsert($calendar, $remote, $generation);
            } catch (\Throwable $e) {
                // One event that can't be stored must not stop the rest of the calendar. A cause that hits every
                // event (the database went away) is reported once, and the others are counted below.
                if ($failures++ === 0) {
                    report($e);
                }
                $event = null;
            }
            if ($event === null) {
                $keep[] = $remote->remoteId; // not stored now: the stored copy stays until the provider deletes the event
            } elseif ($this->tally !== null) {
                if ($event->wasRecentlyCreated) {
                    $this->tally['created']++;
                } elseif (array_diff(array_keys($event->getChanges()), self::BOOKKEEPING)) {
                    $this->tally['updated']++;
                }
            }
        }
        if ($failures > 1) {
            Log::error('More pulled calendar events could not be stored; the first error was reported above', ['calendar_id' => $calendar->id, 'count' => $failures - 1]);
        }
        $this->stamp($calendar, $keep, $generation);
        foreach (array_chunk(array_values(array_unique($changes->deletes)), 500) as $chunk) {
            $this->countDeleted(CalendarEvent::where('calendar_id', $calendar->id)->whereIn('remote_id_hash', array_map('sha1', $chunk))->delete()); // occurrences cascade
        }

        if ($listing) {
            $seen += count($changes->upserts) + count($changes->unchanged);
        }
        if ($listing && $changes->complete) {
            // A listing that ends in an incremental round (the provider gave up on it) is not a full listing: no sweep.
            // Zoho (and others) answer a first listing with nothing: one empty listing never empties a calendar.
            $sweep = $changes->fullListing;
            if ($sweep && $seen === 0 && CalendarEvent::where('calendar_id', $calendar->id)->exists()) {
                $emptyListings++;
                $sweep = $emptyListings >= 2;
            }
            if ($sweep) {
                $emptyListings = 0;
                // A listing that started before its start was kept as a Unix time holds the wall-clock time itself.
                $cutoff = is_string($started) && !ctype_digit($started) ? $started : self::sweepCutoff((int) ($started ?? time()), time(), date_default_timezone_get());
                $this->sweep($calendar, $generation, $cutoff, $provider, $budget);
            }
            $listing = false;
            $seen = 0;
            $started = null;
        }

        $calendar->sync_state = self::withoutEngineKeys($changes->state) + [
            '_generation'      => $generation,
            '_listing'         => $listing,
            '_seen'            => $seen,
            '_listing_started' => $started,
            '_empty_listings'  => $emptyListings,
        ];
        $calendar->save();
    }

    /**
     * The wall-clock time in $timezone (the app's, in which Eloquent writes updated_at) from which a save counts as made
     * after a listing that started at $started (Unix time). That is the listing's start, unless the clock went back
     * since: a save in the repeated hour reads earlier than the start, so the cut-off moves back by as much.
     */
    public static function sweepCutoff(int $started, int $now, string $timezone): string
    {
        $zone = new DateTimeZone($timezone);
        $offset = function (int $time) use ($zone): int {
            return $zone->getOffset(new DateTimeImmutable('@' . $time));
        };
        $back = max(0, $offset($started) - $offset($now));

        return (new DateTimeImmutable('@' . ($started - $back)))->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    private function countDeleted(int $count): void
    {
        if ($this->tally !== null) {
            $this->tally['deleted'] += $count;
        }
    }

    /**
     * Deletes the events a completed listing did not mention: those with an older generation. Events FreeScout saved
     * after the listing started may be missing from it, so they stay. A provider that confirms deletions is asked about
     * them first (those without a remote id were never at the provider and go as they are); the ones it does not
     * confirm gone (still there, or not checked within the budget) get the current generation, so they stay until a
     * later listing leaves them out again.
     */
    private function sweep(Calendar $calendar, int $generation, string $started, CalendarProvider $provider, SyncBudget $budget): void
    {
        $candidates = function () use ($calendar, $generation, $started) {
            // The query builder, so a kept event's updated_at doesn't change: it did not change.
            return DB::table('calendar_events')
                ->where('calendar_id', $calendar->id)
                ->where('sync_generation', '<', $generation)
                ->where('updated_at', '<', $started);
        };
        if (!$provider instanceof ConfirmsDeletions) {
            $this->countDeleted(CalendarEvent::where('calendar_id', $calendar->id)
                ->where('sync_generation', '<', $generation)
                ->where('updated_at', '<', $started)
                ->delete()); // occurrences cascade

            return;
        }
        $remoteIds = $candidates()->whereNotNull('remote_id')->orderBy('id')->pluck('remote_id')->all();
        $gone = $remoteIds ? $provider->confirmGone($calendar, $remoteIds, $budget) : [];
        $this->keepLease(); // the questions may have taken long
        $this->countDeleted(CalendarEvent::whereIn('id', $candidates()->whereNull('remote_id')->pluck('id')->all())->delete());
        foreach (array_chunk(array_values(array_unique($gone)), 500) as $chunk) {
            $this->countDeleted(CalendarEvent::whereIn('id', $candidates()->whereIn('remote_id_hash', array_map('sha1', $chunk))->pluck('id')->all())->delete());
        }
        $candidates()->update(['sync_generation' => $generation]);
    }

    /** Gives events the listing mentioned without a new version the current generation. */
    private function stamp(Calendar $calendar, array $remoteIds, int $generation): void
    {
        foreach (array_chunk(array_values(array_unique($remoteIds)), 500) as $chunk) {
            // The query builder leaves updated_at alone: these events did not change.
            DB::table('calendar_events')
                ->where('calendar_id', $calendar->id)
                ->whereIn('remote_id_hash', array_map('sha1', $chunk))
                ->update(['sync_generation' => $generation]);
        }
    }

    private function succeeded(Calendar $calendar, bool $complete): void
    {
        $now = time();
        $calendar->last_error = null;
        $calendar->sync_failures = 0;
        if ($complete) {
            $calendar->last_synced_at = gmdate('Y-m-d H:i:s', $now);
            $calendar->next_sync_at = $this->nextSync($calendar, gmdate('Y-m-d H:i:s', $now + self::jitter((int) $calendar->sync_interval)));
        } else {
            $calendar->next_sync_at = gmdate('Y-m-d H:i:s', $now); // the budget ran out: the next run continues
        }
        $calendar->save();
    }

    private function failed(Calendar $calendar, string $error, bool $needsReauth): void
    {
        $failures = (int) $calendar->sync_failures + 1;
        $calendar->last_error = $error;
        $calendar->sync_failures = $failures;
        $calendar->next_sync_at = $this->nextSync($calendar, gmdate('Y-m-d H:i:s', time() + self::jitter(self::backoffMinutes((int) $calendar->sync_interval, $failures))));
        $calendar->save();

        $connection = $needsReauth ? $calendar->calendarConnection : null;
        if ($connection !== null && $connection->status !== CalendarConnection::STATUS_NEEDS_REAUTH) {
            // Only while the stored login is still the one the provider refused: a reauthorization that an administrator
            // finished meanwhile stored new credentials, and it wins (as CalDavProvider's refused login does).
            $refused = CalendarConnection::whereKey($connection->id)->where('credentials', $connection->getAttributes()['credentials'] ?? null);
            (clone $refused)->update(['status' => CalendarConnection::STATUS_NEEDS_REAUTH, 'last_error' => $error]);
            // Asked separately: MySQL counts only rows an update changed.
            if ($refused->exists()) {
                $connection->status = CalendarConnection::STATUS_NEEDS_REAUTH;
                $connection->last_error = $error;
                $connection->syncOriginal();
            }
        }
    }

    /**
     * The next sync this run planned, unless someone asked for an earlier one while it ran ("Sync now", the request after
     * a conflict, a reauthorization): a next_sync_at stored meanwhile, before the planned one, stays.
     */
    private function nextSync(Calendar $calendar, string $planned): string
    {
        $stored = DB::table('calendars')->where('id', $calendar->id)->value('next_sync_at');
        if ($stored !== null && (string) $stored !== (string) $calendar->getOriginal('next_sync_at') && (string) $stored < $planned) {
            return (string) $stored;
        }

        return $planned;
    }

    /** Seconds for this many minutes, give or take 10 %, so calendars spread over the runs; at least a minute. */
    private static function jitter(int $minutes): int
    {
        return max(60, (int) round(max(1, $minutes) * 60 * random_int(900, 1100) / 1000));
    }

    private static function withoutEngineKeys(array $state): array
    {
        return array_filter($state, function ($key) {
            return strncmp((string) $key, '_', 1) !== 0;
        }, ARRAY_FILTER_USE_KEY);
    }
}
