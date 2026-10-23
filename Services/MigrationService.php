<?php

namespace Modules\LJPcCalendarModule\Services;

use App\Option;
use App\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;

/**
 * Converts v2 data (public 2.6.x and the 2.7.x fork) to the v3 model. Every step is idempotent so a failed
 * `migrate` can simply run again. Progress and problems go to a report shown under Settings > Calendar.
 */
class MigrationService
{
    public const REPORT_OPTION = 'ljpccalendarmodule.migration_report';
    public const STATE_OPTION = 'ljpccalendarmodule.migration_state';
    public const SKIPPED_OPTION = 'ljpccalendarmodule.migration_skipped_items';

    private const PALETTE = ['#3498db', '#e74c3c', '#e67e22', '#1abc9c', '#9b59b6', '#2ecc71', '#f1c40f', '#833471', '#9980fa'];

    private OccurrenceService $occurrences;

    public function __construct(OccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    /** Entries waiting to be written. Each conversion step flushes once per calendar, inside that calendar's transaction. */
    private array $buffer = [];

    public function report(): array
    {
        $report = Option::get(self::REPORT_OPTION, [], true, false);

        return is_array($report) ? $report : [];
    }

    public function convertCalendars(): void
    {
        foreach (DB::table('calendars')->whereNull('export_token')->orderBy('id')->get() as $row) {
            $this->isolated(
                'Calendar ":calendar" could not be converted and was skipped: :error',
                (string) $row->name,
                function () use ($row) {
                    $this->convertCalendar($row);
                }
            );
        }
        $this->flush();
    }

    public function convertPermissions(): void
    {
        // The legacy permissions column is the done-marker: it is set to NULL once a calendar's permissions are converted.
        foreach (DB::table('calendars')->whereNotNull('permissions')->orderBy('id')->get(['id', 'name', 'permissions']) as $row) {
            $this->isolated(
                'The permissions of calendar ":calendar" could not be converted and were skipped: :error',
                (string) $row->name,
                function () use ($row) {
                    $this->convertCalendarPermissions($row);
                }
            );
        }
        $this->flush();
    }

    /**
     * Runs one calendar's work in its own transaction. Odd data or a database error rolls back that calendar only,
     * is reported as an error (written after the rollback so it persists) and the next calendar goes on.
     */
    private function isolated(string $failureMessage, string $calendarName, callable $work): void
    {
        $this->flush();
        try {
            DB::transaction(function () use ($work) {
                $work();
                $this->flush();
            });
        } catch (\Throwable $e) {
            $this->buffer = [];
            $reason = $e instanceof \Illuminate\Database\QueryException && $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
            $this->log('error', $failureMessage, ['calendar' => $calendarName, 'error' => mb_substr($reason, 0, 500)]);
            $this->flush();
        }
    }

    public function convertItems(): void
    {
        if (!Schema::hasTable('calendar_items')) {
            return;
        }
        $timezone = new DateTimeZone(config('app.timezone') ?: 'UTC');
        $skipped = $this->skippedItemIds();
        $converted = 0;

        DB::table('calendar_items')->orderBy('id')->chunk(200, function ($items) use ($timezone, &$skipped, &$converted) {
            foreach ($items as $item) {
                $id = (int) $item->id;
                if (in_array($id, $skipped, true) || CalendarEvent::where('legacy_id', (string) $id)->where('calendar_id', $item->calendar_id)->exists()) {
                    continue;
                }
                $calendar = Calendar::find($item->calendar_id);
                if ($calendar && $calendar->export_token === null) {
                    // Its calendar's conversion failed (reported there), so its provider is not known yet. Retryable:
                    // neither converted nor skipped, so the cleanup stays blocked until the calendar converts.
                    continue;
                }
                if (!$calendar || !$calendar->isLocal()) {
                    $skipped[] = $id;
                    $this->log('error', $calendar
                        ? 'Event ":title" (#:id) belongs to an external calendar and was not converted; it is listed here with its data.'
                        : 'Event ":title" (#:id) belongs to a calendar that no longer exists and was not converted; it is listed here with its data.', self::fullItemContext($item));
                    continue;
                }
                $buffered = count($this->buffer);
                try {
                    DB::transaction(function () use ($item, $calendar, $timezone) {
                        $this->convertItem($item, $calendar, $timezone);
                    });
                    $converted++;
                } catch (UnconvertibleItem $e) {
                    // Permanent: retrying cannot help, so the full row goes into the report and the item counts as handled.
                    $this->buffer = array_slice($this->buffer, 0, $buffered);
                    $skipped[] = $id;
                    $this->log('error', 'Event ":title" (#:id) could not be converted and was skipped; it is listed here with its data: :error', self::fullItemContext($item) + ['error' => mb_substr($e->getMessage(), 0, 500)]);
                } catch (\Throwable $e) {
                    // Retryable (database error, deadlock, bug): not skipped, so the next run converts it and cleanup stays blocked meanwhile.
                    $this->buffer = array_slice($this->buffer, 0, $buffered);
                    $reason = $e instanceof \Illuminate\Database\QueryException && $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage();
                    $this->log('error', 'Event ":title" (#:id) could not be converted yet; the upgrade will retry it the next time it runs: :error', ['id' => (int) $item->id, 'title' => self::display($item->title), 'error' => mb_substr($reason, 0, 500)]);
                }
            }
            // Report first: a crash between the two writes reports an item twice at worst, and never skips it unreported.
            $this->flush();
            Option::set(self::SKIPPED_OPTION, array_values(array_unique($skipped)));
        });

        $this->log('info', ':count events were converted. All-day events were read in the timezone :timezone.', ['count' => $converted, 'timezone' => $timezone->getName()]);
        $this->flush();
    }

    public function skippedItemIds(): array
    {
        return array_map('intval', (array) Option::get(self::SKIPPED_OPTION, [], true, false));
    }

    /** v2 items that still block the upgrade: neither converted nor skipped. At most $limit, oldest id first. */
    public function pendingItems(int $limit = 200): array
    {
        if (!Schema::hasTable('calendar_items')) {
            return [];
        }
        $done = array_flip(array_merge(
            array_map('strval', CalendarEvent::whereNotNull('legacy_id')->pluck('legacy_id')->all()),
            array_map('strval', $this->skippedItemIds())
        ));
        $names = DB::table('calendars')->pluck('name', 'id')->all();
        $pending = [];
        foreach (DB::table('calendar_items')->orderBy('id')->get(['id', 'calendar_id', 'title', 'start']) as $item) {
            if (isset($done[(string) $item->id])) {
                continue;
            }
            $pending[] = [
                'id'       => (int) $item->id,
                'title'    => self::display($item->title),
                'start'    => self::display($item->start),
                'calendar' => isset($names[$item->calendar_id]) ? (string) $names[$item->calendar_id] : null,
            ];
            if (count($pending) >= $limit) {
                break;
            }
        }

        return $pending;
    }

    /**
     * The escape hatch for an item that keeps failing: it is reported with its full row, so an admin can recreate
     * it by hand, and then counts as handled, like the permanent skips in convertItems().
     */
    public function skipItem(int $id, string $skippedBy): void
    {
        $item = Schema::hasTable('calendar_items') ? DB::table('calendar_items')->where('id', $id)->first() : null;
        if (!$item || in_array($id, $this->skippedItemIds(), true) || CalendarEvent::where('legacy_id', (string) $id)->exists()) {
            throw ValidationException::withMessages(['item' => __('This event can\'t be skipped: it was converted or skipped already, or it no longer exists.')]);
        }
        $this->log('warning', 'Event ":title" (#:id) was skipped by :user and not converted; it is listed here with its data.', self::fullItemContext($item) + ['user' => $skippedBy]);
        // Report first: a crash between the two writes leaves the item pending and reported, never skipped unreported.
        $this->flush();
        Option::set(self::SKIPPED_OPTION, array_values(array_unique(array_merge($this->skippedItemIds(), [$id]))));
    }

    public function scheduleExternalResync(): void
    {
        $count = DB::table('calendars')
            ->whereIn('provider', [Calendar::PROVIDER_ICS, Calendar::PROVIDER_CALDAV])
            ->update(['next_sync_at' => gmdate('Y-m-d H:i:s')]);
        if ($count) {
            $this->log('info', ':count external calendars will be synchronized again; their events appear after the first sync.', ['count' => $count]);
            $this->flush();
        }
    }

    public function isComplete(): bool
    {
        // Every calendar must be converted (it gets export tokens); a calendar that failed conversion blocks cleanup.
        if (Schema::hasColumn('calendars', 'custom_fields') && DB::table('calendars')->whereNull('export_token')->exists()) {
            return false;
        }
        // convertPermissions() sets the legacy column to NULL when done; a non-NULL value means a calendar failed.
        if (Schema::hasColumn('calendars', 'permissions') && DB::table('calendars')->whereNotNull('permissions')->exists()) {
            return false;
        }
        if (!Schema::hasTable('calendar_items')) {
            return true;
        }
        $itemIds = array_map('strval', DB::table('calendar_items')->pluck('id')->all());
        if (!$itemIds) {
            return true;
        }
        // Compared as strings so a non-numeric legacy_id (a future CalDAV UID) can never match an item id.
        $converted = array_map('strval', CalendarEvent::whereNotNull('legacy_id')->pluck('legacy_id')->all());
        $skipped = array_map('strval', $this->skippedItemIds());

        return !array_diff($itemIds, $converted, $skipped);
    }

    public function isFinished(): bool
    {
        return Option::get(self::STATE_OPTION, '', true, false) === 'complete';
    }

    public function finish(): void
    {
        Option::set(self::STATE_OPTION, 'complete');
        $this->log('info', 'The upgrade to version 3 is complete.');
        $this->flush();
    }

    private function convertItem(object $item, Calendar $calendar, DateTimeZone $timezone): void
    {
        $utc = new DateTimeZone('UTC');
        $start = self::utcTime($item->start, 'start');
        $end = self::utcTime($item->end, 'end');
        [$customFields, $status] = self::decode($item->custom_fields ?? null);
        if ($status === 'invalid') {
            $this->log('warning', 'The custom fields of event ":title" (#:id) could not be read; it was converted without field values and links.', self::itemContext($item));
        } elseif ($status === 'double') {
            $this->log('warning', 'The custom fields of event ":title" (#:id) were stored double-encoded; they were read anyway.', self::itemContext($item));
        }

        if ($item->is_all_day) {
            $local = $start->setTimezone($timezone);
            $startDate = ((int) $local->format('H') >= 12 ? $local->modify('+1 day') : $local)->format('Y-m-d');
            $days = (int) round(($end->getTimestamp() - $start->getTimestamp() + 1) / 86400);
            if ($days < 1) {
                $this->log('warning', 'The end of all-day event ":title" (#:id) is before its start; it was set to one day. Original start :start, end :end.', self::itemContext($item));
                $days = 1;
            }
            $times = ['all_day' => true, 'start' => $startDate, 'end' => (new DateTimeImmutable($startDate, $utc))->modify('+' . $days . ' days')->format('Y-m-d')];
        } else {
            $startLocal = $start->setTimezone($timezone);
            if ($end < $start) {
                $this->log('warning', 'The end of event ":title" (#:id) is before its start; the end was set to the start. Original start :start, end :end.', self::itemContext($item));
            }
            $times = ['all_day' => false, 'start' => $startLocal, 'end' => max($startLocal, $end->setTimezone($timezone))];
        }

        $fieldValues = [];
        foreach ($customFields as $key => $value) {
            if (str_starts_with((string) $key, 'custom_field_') && $value !== null && $value !== '') {
                $fieldValues[substr((string) $key, 13)] = $value;
            }
        }

        $event = new CalendarEvent([
            'calendar_id'     => $calendar->id,
            'author_id'       => $item->author_id ?: ($customFields['author_id'] ?? null),
            'conversation_id' => isset($customFields['conversation_id']) && is_numeric($customFields['conversation_id']) ? (int) $customFields['conversation_id'] : null,
            'field_values'    => $fieldValues,
            'title_input'     => (string) $item->title,
            'legacy_id'       => (string) $item->id,
        ]);
        $event->uid = 'v2-item-' . $item->id . '@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'freescout');
        $event->setRelation('calendar', $calendar);

        $vcal = EventIcs::build(['uid' => $event->uid, 'title' => (string) $item->title, 'location' => $item->location, 'description' => $item->body] + $times);
        $event->ics = $vcal->serialize();
        $event->fill(EventIcs::derive($vcal, $event->uid, $calendar->timezoneName()));
        $event->save();
        $this->occurrences->refresh($event);
    }

    /**
     * A v2 start or end is a UTC `Y-m-d H:i:s`. On PostgreSQL v2's dateTimeTz columns are "timestamp with time zone",
     * which read back with the session's offset ("2023-01-12 16:45:00+00"), so an offset is applied. NULL, empty,
     * unparsable and zero dates are rejected.
     */
    private static function utcTime($value, string $label): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $time = false;
        if (is_string($value) && preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)([+-]\d\d)(?::?(\d\d))?$/', $value, $parts)) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP', $parts[1] . $parts[2] . ':' . ($parts[3] ?? '00'));
        } elseif (is_string($value)) {
            $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        }
        $errors = DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors && ($errors['warning_count'] || $errors['error_count'])) || (int) $time->setTimezone($utc)->format('Y') < 1900) {
            throw new UnconvertibleItem('The ' . $label . ' time "' . self::display($value) . '" is not a usable date.');
        }

        return $time->setTimezone($utc);
    }

    private static function itemContext(object $item): array
    {
        return [
            'id'          => (int) $item->id,
            'title'       => self::display($item->title),
            'calendar_id' => (int) $item->calendar_id,
            'start'       => self::display($item->start),
            'end'         => self::display($item->end),
        ];
    }

    /** Everything an admin needs to recreate the event by hand. */
    private static function fullItemContext(object $item): array
    {
        return self::itemContext($item) + [
            'is_all_day'    => (int) $item->is_all_day,
            'location'      => self::display($item->location ?? null),
            'body'          => mb_substr(self::display($item->body ?? null), 0, 2000),
            'custom_fields' => mb_substr(self::display($item->custom_fields ?? null), 0, 2000),
        ];
    }

    private function convertCalendar(object $row): void
    {
        $name = (string) $row->name;
        [$customFields, $status] = self::decode($row->custom_fields ?? null);
        if ($status === 'invalid') {
            $this->log('warning', 'The custom fields and connection settings of ":calendar" could not be read and were left empty.', ['calendar' => $name]);
        } elseif ($status === 'double') {
            $this->log('warning', 'The custom fields of ":calendar" were stored double-encoded; they were read anyway.', ['calendar' => $name]);
        }

        $providers = ['normal' => Calendar::PROVIDER_LOCAL, 'ics' => Calendar::PROVIDER_ICS, 'caldav' => Calendar::PROVIDER_CALDAV];
        $provider = $providers[$row->type ?? 'normal'] ?? null;
        if ($provider === null) {
            $this->log('warning', 'Calendar ":calendar" had an unknown type ":type" and was converted to a local calendar.', ['calendar' => $name, 'type' => (string) $row->type]);
            $provider = Calendar::PROVIDER_LOCAL;
        }
        $rawFields = $customFields['fields'] ?? [];
        if (!is_array($rawFields)) {
            $this->log('warning', 'The custom fields and connection settings of ":calendar" could not be read and were left empty.', ['calendar' => $name]);
            $rawFields = [];
        }
        $fields = $this->fields($name, $rawFields);

        $update = [
            'provider'          => $provider,
            'fields'            => json_encode($fields),
            'display_priority'  => isset($customFields['display_priority']) && is_numeric($customFields['display_priority'])
                ? (int) $customFields['display_priority']
                : Calendar::defaultPriority($provider),
            'title_template'    => $this->template($name, (string) ($row->title_template ?? ''), $fields),
            'color'             => $this->color($name, $row->color ?? null, (int) $row->id),
            'export_token'      => Calendar::newToken(),
            'export_busy_token' => Calendar::newToken(),
        ];
        $url = '';
        $userinfo = null;
        if ($provider !== Calendar::PROVIDER_LOCAL) {
            $update['sync_interval'] = $this->syncInterval($name, $customFields);
            // A username and password in the address ("https://user:pass@host/...") never stay in it.
            [$url, $userinfo] = self::withoutUserinfo(self::text($customFields['url'] ?? null));
            if ($url === '') {
                $this->log('error', 'Calendar ":calendar" has no URL and cannot sync until one is set.', ['calendar' => $name]);
            }
            $update['remote_id'] = $url !== '' ? $url : null;
            if ($userinfo !== null) {
                $customFields['url'] = $url;
                $update['custom_fields'] = json_encode($customFields);
            }
        }
        if ($provider === Calendar::PROVIDER_ICS && $userinfo !== null) {
            $this->log('warning', 'The address of feed ":calendar" held a username and password. FreeScout keeps no passwords for feeds, so they were removed; if the feed needs them, it can\'t sync until it gets an address that works without them.', ['calendar' => $name]);
        }
        if ($provider === Calendar::PROVIDER_CALDAV) {
            $update['connection_id'] = $this->caldavConnection($name, $customFields, $url, $userinfo)->id;
            // The encrypted copy lives in calendar_connections; the legacy plaintext must not stay behind.
            if ($status === 'double' || array_key_exists('username', $customFields) || array_key_exists('password', $customFields)) {
                unset($customFields['username'], $customFields['password']);
                $update['custom_fields'] = json_encode($customFields);
            }
        }

        DB::table('calendars')->where('id', $row->id)->update($update);
        $this->log('info', 'Calendar ":calendar" was converted (:provider).', ['calendar' => $name, 'provider' => $provider]);
    }

    private function convertCalendarPermissions(object $calendar): void
    {
        $name = (string) $calendar->name;
        [$permissions, $status] = self::decode($calendar->permissions);
        if ($status === 'invalid') {
            // Reported with the raw value, then cleared like a converted calendar so the done-marker lets cleanup verify completeness.
            $this->log('warning', 'The permissions of ":calendar" could not be read and were not converted; the value was cleared after this report.', ['calendar' => $name, 'raw' => mb_substr(self::display($calendar->permissions), 0, 500)]);
            DB::table('calendars')->where('id', $calendar->id)->update(['permissions' => null]);

            return;
        }
        if ($status === 'double') {
            $this->log('warning', 'The permissions of ":calendar" were stored double-encoded; they were read anyway.', ['calendar' => $name]);
        }
        foreach ($permissions as $subjectKey => $rights) {
            if (!ctype_digit((string) $subjectKey)) {
                $this->log('warning', 'A permission on ":calendar" for ":subject", which is not a user or team id, was skipped.', ['calendar' => $name, 'subject' => (string) $subjectKey]);
                continue;
            }
            if (!is_array($rights)) {
                $this->log('warning', 'The permission on ":calendar" for subject ":subject" is not a set of rights and was skipped.', ['calendar' => $name, 'subject' => (string) $subjectKey]);
                continue;
            }
            $this->convertPermission($calendar, (int) $subjectKey, $rights);
        }
        DB::table('calendars')->where('id', $calendar->id)->update(['permissions' => null]);
    }

    private function convertPermission(object $calendar, int $subjectId, array $v2): void
    {
        $rights = [
            'show_in_dashboard' => self::bool($v2['showInDashboard'] ?? null),
            'can_view'          => self::bool($v2['showInCalendar'] ?? null),
            'can_create'        => self::bool($v2['createItems'] ?? null),
            'can_edit'          => self::bool($v2['editItems'] ?? null),
        ];
        if (!array_filter($rights)) {
            return;
        }
        $user = User::find($subjectId);
        $isTeam = $user && (int) $user->type === User::TYPE_ROBOT && str_starts_with((string) $user->email, 'fsteam');
        if (!$user || (!$isTeam && (int) $user->status === User::STATUS_DELETED)) {
            $this->log('warning', 'A permission on ":calendar" for a deleted user or team (#:id) was dropped.', ['calendar' => $calendar->name, 'id' => $subjectId]);

            return;
        }

        CalendarPermission::create([
            'calendar_id'  => $calendar->id,
            'subject_type' => $isTeam ? CalendarPermission::SUBJECT_TEAM : CalendarPermission::SUBJECT_USER,
            'subject_id'   => $subjectId,
        ] + $rights);
        if ($isTeam) {
            $this->log('info', 'Team ":team" now gives its members access to ":calendar". Team permissions had no effect in version 2, so check this row.', ['team' => (string) $user->first_name, 'calendar' => $calendar->name]);
        }
    }

    private function fields(string $calendar, array $fields): array
    {
        $result = [];
        $names = [];
        $position = 0;
        foreach ($fields as $field) {
            $position++;
            $hasName = is_array($field) && is_scalar($field['name'] ?? null) && trim((string) $field['name']) !== '';
            $hasId = is_array($field) && is_scalar($field['id'] ?? null) && (string) $field['id'] !== '';
            if (!$hasName || !$hasId) {
                $context = ['calendar' => $calendar, 'position' => $position];
                if (is_array($field) && is_scalar($field['name'] ?? null)) {
                    $context['name'] = (string) $field['name'];
                }
                $this->log('warning', 'Custom field :position on ":calendar" has no usable id or name and was dropped.', $context);
                continue;
            }
            $name = trim(strip_tags((string) $field['name']));

            $options = $field['options'] ?? [];
            if (is_string($options)) {
                $options = explode(',', $options);
            }
            $cleanOptions = [];
            foreach (is_array($options) ? $options : [] as $option) {
                if (!is_scalar($option)) {
                    $this->log('warning', 'An option of field ":field" on ":calendar" is not text and was dropped.', ['calendar' => $calendar, 'field' => $name]);
                    continue;
                }
                if (trim((string) $option) !== '') {
                    $cleanOptions[] = trim((string) $option);
                }
            }

            $type = $field['type'] ?? 'text';
            if (!is_string($type) || !in_array($type, FieldValues::TYPES, true)) {
                $this->log('warning', 'Field ":field" on ":calendar" had an unknown type ":type" and was converted to text.', ['calendar' => $calendar, 'field' => $name, 'type' => self::display($type)]);
                $type = 'text';
            }

            if (isset($names[$name])) {
                $this->log('warning', 'Calendar ":calendar" has more than one field named ":field"; the title template uses the first one.', ['calendar' => $calendar, 'field' => $name]);
            }
            $id = is_numeric($field['id']) ? (int) $field['id'] : (string) $field['id'];
            $names[$name] ??= $id;

            $result[] = [
                'id'       => $id,
                'name'     => $name,
                'type'     => $type,
                'required' => self::bool($field['required'] ?? null),
                'options'  => $cleanOptions,
            ];
        }

        return $result;
    }

    private function template(string $calendar, string $template, array $fields): ?string
    {
        $template = trim($template);
        if ($template === '') {
            return null;
        }
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field['name']] ??= $field['id'];
        }

        return preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function (array $match) use ($byName, $calendar) {
            if ($match[1] === 'title') {
                return '{{title}}';
            }
            if (isset($byName[$match[1]])) {
                return '{{field:' . $byName[$match[1]] . '}}';
            }
            $this->log('warning', 'The title template of ":calendar" uses ":placeholder", which matches no field; it was left as text.', ['calendar' => $calendar, 'placeholder' => $match[0]]);

            return $match[0];
        }, $template);
    }

    /** A missing refresh gives daily (the v2 default for converted feeds); an unknown value is reported. */
    private function syncInterval(string $calendar, array $customFields): int
    {
        $refresh = $customFields['refresh'] ?? null;
        if ($refresh === null) {
            return Calendar::SYNC_INTERVALS['daily'];
        }
        if (is_string($refresh) && isset(Calendar::SYNC_INTERVALS[$refresh])) {
            return Calendar::SYNC_INTERVALS[$refresh];
        }
        $this->log('warning', 'Calendar ":calendar" had an unknown refresh interval ":refresh" and now syncs daily.', ['calendar' => $calendar, 'refresh' => self::display($refresh)]);

        return Calendar::SYNC_INTERVALS['daily'];
    }

    /** $url is the address without credentials; $userinfo the ones it had, which fill in a missing username or password. */
    private function caldavConnection(string $calendar, array $customFields, string $url, ?array $userinfo): CalendarConnection
    {
        $username = self::text($customFields['username'] ?? null);
        $password = self::text($customFields['password'] ?? null);
        if ($userinfo !== null) {
            $taken = ($username === '' && $userinfo['username'] !== '') || ($password === '' && $userinfo['password'] !== '');
            $username = $username !== '' ? $username : $userinfo['username'];
            $password = $password !== '' ? $password : $userinfo['password'];
            $this->log('warning', $taken
                ? 'The address of calendar ":calendar" held its CalDAV login; it was moved to the calendar\'s connection and removed from the address.'
                : 'The address of calendar ":calendar" held a CalDAV login; it was removed from the address, and the username and password set on the calendar are used.', ['calendar' => $calendar]);
        }
        $parts = parse_url($url) ?: [];
        $missing = $username === '' || $password === '';
        $connection = new CalendarConnection([
            'provider'   => Calendar::PROVIDER_CALDAV,
            'account'    => $username,
            'server_url' => isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : null,
            'status'     => $missing ? 'error' : 'ok',
            'last_error' => $missing ? 'Missing CalDAV username or password' : null,
        ]);
        if ($missing) {
            $this->log('warning', 'Calendar ":calendar" has no CalDAV username or password; its connection was created with an error status.', ['calendar' => $calendar]);
        }
        $connection->setCredentials(['username' => $username, 'password' => $password]);
        $connection->save();

        return $connection;
    }

    private function color(string $calendar, $color, int $id): string
    {
        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}\z/', $color)) {
            return $color;
        }
        if (is_string($color) && preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])\z/', $color, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        $this->log('warning', 'The color ":color" of ":calendar" is not a hex color and was replaced by one from the palette.', ['calendar' => $calendar, 'color' => self::display($color)]);

        return self::PALETTE[$id % count(self::PALETTE)];
    }

    /**
     * The address without "user:password@", and the username and password it held (URL-decoded), or null when it had
     * none. The credentials end at the last "@" before the path, so an unencoded "@" in a password is kept with it.
     *
     * @return array{0: string, 1: array{username: string, password: string}|null}
     */
    private static function withoutUserinfo(string $url): array
    {
        if (!preg_match('~^([a-z][a-z0-9+.\-]*://)([^/?#]*)(.*)$~is', $url, $match) || strpos($match[2], '@') === false) {
            return [$url, null];
        }
        $at = strrpos($match[2], '@');
        $userinfo = explode(':', substr($match[2], 0, $at), 2);

        return [$match[1] . substr($match[2], $at + 1) . $match[3], ['username' => rawurldecode($userinfo[0]), 'password' => rawurldecode($userinfo[1] ?? '')]];
    }

    private static function bool($value): bool
    {
        return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    private static function text($value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** A scalar-only rendering of any value for a report context. */
    private static function display($value): string
    {
        return is_scalar($value) ? (string) $value : ($value === null ? '' : 'non-scalar ' . gettype($value));
    }

    /** @return array{0: array, 1: string} the decoded array and 'ok', 'double' (a JSON string holding JSON) or 'invalid' (then []). */
    private static function decode($value): array
    {
        if ($value === null || $value === '') {
            return [[], 'ok'];
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (is_string($decoded)) {
            $inner = json_decode($decoded, true);

            return is_array($inner) ? [$inner, 'double'] : [[], 'invalid'];
        }
        if (is_array($decoded)) {
            return [$decoded, 'ok'];
        }

        return is_string($value) && trim($value) === 'null' ? [[], 'ok'] : [[], 'invalid'];
    }

    /** Buffers a report entry; flush() writes the buffer. Steps that log outside convertCalendars/convertPermissions must flush. */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->buffer[] = ['level' => $level, 'message' => $message, 'context' => $context, 'at' => gmdate('Y-m-d H:i:s')];
        if ($level === 'error') {
            Log::error('Calendar migration: ' . __($message, array_map('strval', $context)));
        }
    }

    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }
        // Identical entries (the same retry error, "0 events were converted") are kept once, so retries don't grow the report.
        $report = $this->report();
        $seen = [];
        foreach ($report as $entry) {
            $seen[self::entryKey($entry)] = true;
        }
        foreach ($this->buffer as $entry) {
            $key = self::entryKey($entry);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $report[] = $entry;
            }
        }
        Option::set(self::REPORT_OPTION, $report);
        $this->buffer = [];
    }

    private static function entryKey(array $entry): string
    {
        // serialize() is binary-safe: json_encode() returns false for invalid UTF-8, which would make distinct entries collide.
        return serialize([$entry['level'] ?? '', $entry['message'] ?? '', $entry['context'] ?? []]);
    }
}
