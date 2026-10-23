<?php

namespace Modules\LJPcCalendarModule\Services;

use Illuminate\Support\Facades\DB;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavClient;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\SettingsPage;

/**
 * The pick screen of a connected account: its calendars, which of them FreeScout already shows, and adding the ones an
 * administrator ticks. Listing also brings the read-only flag of the added calendars up to date, so a calendar whose
 * write access was taken away (or given back) at the provider follows at once: the flag is checked on every request.
 */
class CalendarPicker
{
    private ProviderRegistry $providers;

    public function __construct(ProviderRegistry $providers)
    {
        $this->providers = $providers;
    }

    /**
     * The account's calendars in the provider's order, each with the calendar it was added as (null when not added).
     * $owner is a Microsoft shared mailbox address.
     *
     * @return array<int, array{remote: RemoteCalendar, calendar: ?Calendar}>
     * @throws ProviderException when the provider can't list them
     */
    public function list(CalendarConnection $connection, ?string $owner = null): array
    {
        $remotes = $this->providers->forProvider($connection->provider)->listCalendars($connection, $owner);
        $added = Calendar::where('connection_id', $connection->id)->get();
        $items = [];
        foreach ($remotes as $remote) {
            $calendar = $added->first(function (Calendar $calendar) use ($connection, $remote) {
                return self::same($connection->provider, (string) $calendar->remote_id, $remote->remoteId);
            });
            if ($calendar !== null && $calendar->remote_read_only !== !$remote->writable) {
                $calendar->remote_read_only = !$remote->writable;
                $calendar->save();
            }
            $items[] = ['remote' => $remote, 'calendar' => $calendar];
        }

        return $items;
    }

    /**
     * Adds the ticked calendars that the account has and that are not added yet, in one transaction, due for their
     * first sync now. Names stay unique (" (2)" and so on), colors and zones are kept when valid. The connection's row is
     * locked while it adds, so a second post of the same pick (a double-click) waits and then adds nothing twice.
     *
     * @param string[] $remoteIds
     * @return Calendar[] the calendars added, in the provider's order
     * @throws ProviderException when the provider can't list the account's calendars
     */
    public function add(CalendarConnection $connection, ?string $owner, array $remoteIds): array
    {
        $picked = array_filter($this->list($connection, $owner), function (array $item) use ($remoteIds) {
            return $item['calendar'] === null && in_array($item['remote']->remoteId, $remoteIds, true);
        });

        return DB::transaction(function () use ($connection, $picked) {
            CalendarConnection::whereKey($connection->id)->lockForUpdate()->first();
            $present = Calendar::where('connection_id', $connection->id)->pluck('remote_id')->all();
            $added = [];
            foreach ($picked as $item) {
                /** @var RemoteCalendar $remote */
                $remote = $item['remote'];
                foreach ($present as $stored) {
                    if (self::same($connection->provider, (string) $stored, $remote->remoteId)) {
                        continue 2; // added while the account was listed
                    }
                }
                $added[] = Calendar::create([
                    'provider'         => $connection->provider,
                    'connection_id'    => $connection->id,
                    'remote_id'        => $remote->remoteId,
                    'name'             => self::uniqueName($remote->name),
                    'color'            => self::color($remote->color),
                    'timezone'         => $remote->timezone !== null && VTimezone::isNamedZone($remote->timezone) ? $remote->timezone : null,
                    'remote_read_only' => !$remote->writable,
                    'next_sync_at'     => gmdate('Y-m-d H:i:s'),
                ]);
            }

            return $added;
        });
    }

    /** A CalDAV collection has one address with or without its trailing slash; other providers' ids are exact. */
    private static function same(string $provider, string $stored, string $remoteId): bool
    {
        return $provider === Calendar::PROVIDER_CALDAV ? CalDavClient::sameResource($stored, $remoteId) : $stored === $remoteId;
    }

    /** Calendar names are unique (the settings form requires it); a taken name gets " (2)", " (3)" and so on. */
    private static function uniqueName(string $name): string
    {
        $base = mb_substr(trim(strip_tags($name)), 0, 180);
        $base = $base !== '' ? $base : __('Calendar');
        $candidate = $base;
        for ($number = 2; Calendar::where('name', $candidate)->exists(); $number++) {
            $candidate = $base . ' (' . $number . ')';
        }

        return $candidate;
    }

    /** The provider's color as #rrggbb, or a swatch when it has none FreeScout can show (the next one per calendar). */
    private static function color(?string $color): string
    {
        if (SettingsPage::isColor($color)) {
            return strtolower($color);
        }

        return SettingsPage::SWATCHES[Calendar::count() % count(SettingsPage::SWATCHES)];
    }
}
