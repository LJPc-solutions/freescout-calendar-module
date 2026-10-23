<?php

namespace Modules\LJPcCalendarModule\Http;

use App\Mailbox;
use App\User;
use DateTimeZone;
use Illuminate\Contracts\Validation\Rule as ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\Ics\IcsProvider;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Policies\CalendarPolicy;
use Modules\LJPcCalendarModule\Services\FieldValues;
use Modules\LJPcCalendarModule\Services\TitleTemplate;

/**
 * The calendar settings form posts a whole calendar at once (general settings, custom fields, permission matrix);
 * this validates it and turns it into the calendar's columns and permission rows.
 */
final class CalendarForm
{
    /** The rights a read-only calendar (Calendar::isReadOnly()) never takes from the form, which doesn't offer them. */
    public const WRITE_RIGHTS = ['can_create', 'can_edit'];

    /** Provider (local or ics), name and color of a new calendar; an ICS feed also has its address and sync interval. */
    public static function validateNew(Request $request): array
    {
        self::cleanName($request);
        self::cleanUrl($request);
        $rules = [
            'provider' => ['nullable', Rule::in([Calendar::PROVIDER_LOCAL, Calendar::PROVIDER_ICS])],
            'name'     => ['required', 'string', 'max:191', Rule::unique('calendars', 'name')],
            'color'    => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'],
        ];
        $feed = $request->input('provider') === Calendar::PROVIDER_ICS;
        $data = $request->validate($rules + ($feed ? self::syncRules(true) : []), self::syncMessages());

        $calendar = ['provider' => $feed ? Calendar::PROVIDER_ICS : Calendar::PROVIDER_LOCAL, 'name' => $data['name'], 'color' => $data['color']];
        if ($feed) {
            $calendar += ['remote_id' => $data['url'], 'sync_interval' => (int) $data['sync_interval']];
        }

        return $calendar;
    }

    /** @return array the calendar's columns plus "permissions", the rows for syncPermissions() */
    public static function validate(Request $request, Calendar $calendar): array
    {
        // PHP drops input beyond max_input_vars without an error. This field comes last, so it is missing then.
        if ($request->input('form_complete') !== '1') {
            throw ValidationException::withMessages(['form' => __('The server cut this form off before it was complete, so nothing was saved. Ask your host to raise PHP\'s max_input_vars, then save again.')]);
        }
        self::cleanName($request);
        self::cleanUrl($request);
        $feed = $calendar->provider === Calendar::PROVIDER_ICS;
        $data = $request->validate([
            'name'                     => ['required', 'string', 'max:191', Rule::unique('calendars', 'name')->ignore($calendar->id)],
            'color'                    => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}\z/'],
            'display_priority'         => 'required|integer|min:0|max:9999',
            'timezone'                 => ['nullable', Rule::in(self::timezones($calendar))],
            'default_reminder_minutes' => 'nullable|integer|min:0|max:40320',
            'title_template'           => 'nullable|string|max:191',
            'fields'                   => 'nullable|array',
            'fields.*.type'            => ['required', Rule::in(FieldValues::TYPES)],
            'permissions'              => 'nullable|array',
        ] + ($calendar->isLocal() ? [] : self::syncRules($feed, true, $calendar->remote_id)), self::syncMessages());

        $fields = self::fields((array) $request->input('fields', []), $calendar->fieldDefinitions());
        $template = trim((string) ($data['title_template'] ?? ''));
        if (array_diff(TitleTemplate::fieldIds($template), array_map('strval', array_column($fields, 'id')))) {
            throw ValidationException::withMessages(['title_template' => __('The title template uses a field that does not exist.')]);
        }

        $columns = [
            'name'                     => $data['name'],
            'color'                    => $data['color'],
            'enabled'                  => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN),
            'display_priority'         => (int) $data['display_priority'],
            'timezone'                 => $data['timezone'] ?? null,
            'default_reminder_minutes' => isset($data['default_reminder_minutes']) ? (int) $data['default_reminder_minutes'] : null,
            'title_template'           => $template === '' ? null : $template,
            'fields'                   => $fields,
            'permissions'              => self::permissionRows((array) $request->input('permissions', []), $calendar),
        ];
        if (!$calendar->isLocal() && isset($data['sync_interval'])) {
            $columns['sync_interval'] = (int) $data['sync_interval'];
        }
        if ($feed && isset($data['url'])) {
            $columns['remote_id'] = $data['url'];
        }

        return $columns;
    }

    public static function syncPermissions(Calendar $calendar, array $rows): void
    {
        CalendarPermission::where('calendar_id', $calendar->id)->whereIn('subject_type', self::editableSubjectTypes())->delete();
        foreach ($rows as $row) {
            CalendarPermission::create(['calendar_id' => $calendar->id] + $row);
        }
    }

    /** The subject types the form shows. Teams only appear while the Teams module is active, so their rows are left alone otherwise. */
    public static function editableSubjectTypes(): array
    {
        $types = [CalendarPermission::SUBJECT_USER, CalendarPermission::SUBJECT_MAILBOX];
        if (CalendarPolicy::teamsActive()) {
            $types[] = CalendarPermission::SUBJECT_TEAM;
        }

        return $types;
    }

    private static function cleanName(Request $request): void
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim(strip_tags($request->input('name')))]);
        }
    }

    /**
     * The zones the edit form offers: PHP's current names, plus the calendar's own zone when that is an older name PHP
     * still knows (a provider may report Asia/Calcutta), so a save keeps it.
     */
    public static function timezones(Calendar $calendar): array
    {
        $zones = DateTimeZone::listIdentifiers();
        $own = (string) $calendar->timezone;
        if ($own !== '' && !in_array($own, $zones, true)) {
            array_unshift($zones, $own);
        }

        return $zones;
    }

    private static function cleanUrl(Request $request): void
    {
        if (is_string($request->input('url'))) {
            $request->merge(['url' => trim($request->input('url'))]);
        }
    }

    /** Whether an address carries a username or password (https://user:password@host/). */
    public static function hasLogin(string $url): bool
    {
        return (bool) preg_match('~^[a-z][a-z0-9+.\-]*://[^/?#]*@~i', $url);
    }

    /**
     * The sync interval of an external calendar, and the address of an ICS feed. On the edit form a field that is not
     * posted at all keeps its stored value; an empty one is an error. $storedUrl is the feed's address as saved.
     */
    private static function syncRules(bool $feed, bool $keepMissing = false, ?string $storedUrl = null): array
    {
        $presence = $keepMissing ? ['sometimes', 'required'] : ['required'];
        $rules = ['sync_interval' => array_merge($presence, ['integer', Rule::in(array_values(Calendar::SYNC_INTERVALS))])];
        if ($feed) {
            $rules['url'] = array_merge(['bail'], $presence, ['string', 'max:2000', 'regex:~^(https?|webcals?)://[^\s/?#]+~i', self::feedAddress($storedUrl)]);
        }

        return $rules;
    }

    private static function syncMessages(): array
    {
        return [
            'url.required'           => __('Enter the link of the calendar feed.'),
            'url.regex'              => __('Enter an address that starts with https://, http:// or webcal://.'),
            'sync_interval.required' => __('Choose how often FreeScout checks the calendar.'),
            'sync_interval.integer'  => __('Choose how often FreeScout checks the calendar.'),
            'sync_interval.in'       => __('Choose how often FreeScout checks the calendar.'),
        ];
    }

    /**
     * A new feed address carries no login (FreeScout keeps no passwords for feeds, as the v2 upgrade decided) and its
     * host is one FreeScout may contact (APP_REMOTE_HOST_WHITE_LIST), checked the way the sync reads the feed: webcal://
     * over https. Nothing is requested here; calendar:sync reads the feed. The stored address isn't checked again, so a
     * feed whose host is refused (an upgraded intranet feed) doesn't block other changes; its sync reports the refusal.
     */
    private static function feedAddress(?string $storedUrl): ValidationRule
    {
        return new class ($storedUrl) implements ValidationRule {
            private ?string $storedUrl;

            private string $message = '';

            public function __construct(?string $storedUrl)
            {
                $this->storedUrl = $storedUrl;
            }

            public function passes($attribute, $value): bool
            {
                $value = (string) $value;
                if ($this->storedUrl !== null && $value === $this->storedUrl) {
                    return true;
                }
                if (CalendarForm::hasLogin($value)) {
                    $this->message = __('Leave the username and password out of the link. FreeScout keeps no passwords for feeds.');

                    return false;
                }
                try {
                    app(HttpClientFactory::class)->assertAllowed(IcsProvider::feedUrl($value));
                } catch (ProviderUnavailable $e) {
                    $this->message = $e->userMessage();

                    return false;
                }

                return true;
            }

            public function message(): string
            {
                return $this->message;
            }
        };
    }

    /** Rows in posted order. Ticked "remove" rows and the empty row for a new field are dropped. */
    private static function fields(array $rows, array $definitions): array
    {
        $stored = [];
        foreach ($definitions as $definition) {
            if (is_array($definition) && isset($definition['id']) && (is_int($definition['id']) || is_string($definition['id']))) {
                $stored[(string) $definition['id']] = $definition['id'];
            }
        }
        $result = [];
        $used = [];
        $errors = [];
        foreach ($rows as $key => $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            if (array_filter(array_intersect_key($row, array_flip(['id', 'name', 'type', 'options'])), function ($value) {
                return is_array($value);
            })) {
                $errors["fields.$key.name"] = __('Give this field a name of at most 100 characters.');
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $name = trim(strip_tags((string) ($row['name'] ?? '')));
            $options = self::options((string) ($row['options'] ?? ''));
            if ($id === '' && $name === '' && !$options) {
                continue;
            }
            $type = (string) $row['type'];
            $hasOptions = in_array($type, ['dropdown', 'multiselect'], true);
            if ($name === '' || mb_strlen($name) > 100) {
                $errors["fields.$key.name"] = __('Give this field a name of at most 100 characters.');
                continue;
            }
            if ($hasOptions && (!$options || count($options) > 100 || max(array_map('mb_strlen', $options)) > 100)) {
                $errors["fields.$key.options"] = __('A dropdown or multiple choice field needs 1 to 100 options of at most 100 characters, one per line.');
                continue;
            }
            $result[] = [
                'id'       => self::fieldId($id, $used, $stored),
                'name'     => $name,
                'type'     => $type,
                'required' => !empty($row['required']),
                'options'  => $hasOptions ? $options : [],
            ];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (count($result) > 50) {
            throw ValidationException::withMessages(['fields' => __('A calendar can have at most 50 custom fields.')]);
        }

        return $result;
    }

    private static function options(string $text): array
    {
        $options = array_map(function (string $line) {
            return trim(strip_tags($line));
        }, preg_split('/\r\n|\r|\n/', $text));

        return array_values(array_unique(array_filter($options, 'strlen')));
    }

    /**
     * Keeps an existing id. An id the calendar already stores is kept as stored, even one that breaks the rule below
     * (from v2 or the 2.7.x fork), because its events' values and the title template refer to it. Otherwise a
     * missing, malformed, duplicate or small (v2-looking) id gets a fresh one. Leading zeros are dropped first,
     * because "01000" and "1000" would collide once stored as a number.
     *
     * @param array $stored the stored ids, keyed by their text
     */
    private static function fieldId(string $id, array &$used, array $stored)
    {
        if ($id !== '' && array_key_exists($id, $stored) && !isset($used[$id])) {
            $used[$id] = true;

            return $stored[$id];
        }
        $normalized = ctype_digit($id) ? ltrim($id, '0') : $id;
        $usable = $normalized !== '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $normalized)
            && !(ctype_digit($normalized) && ((int) $normalized < 1000 || strlen($normalized) > 18))
            && !isset($used[$normalized]);
        while (!$usable) {
            $normalized = (string) ((int) (microtime(true) * 1000) + random_int(0, 999) + count($used));
            $usable = !isset($used[$normalized]);
        }
        $used[$normalized] = true;

        return ctype_digit($normalized) ? (int) $normalized : $normalized;
    }

    /**
     * permissions[<type>][<id>][<right>] = 1 into rows; a subject without any right gets no row. A read-only calendar
     * takes no write rights from the form. One the account may only read (remote_read_only) keeps its stored ones, which
     * count again once the account may write; an ICS feed never can, so it keeps none.
     */
    private static function permissionRows(array $input, Calendar $calendar): array
    {
        $readOnly = $calendar->isReadOnly();
        $kept = $calendar->remote_read_only ? self::storedWriteRights($calendar) : [];
        $rows = [];
        $errors = [];
        foreach (self::editableSubjectTypes() as $type) {
            foreach ((array) ($input[$type] ?? []) as $id => $rights) {
                $row = ['subject_type' => $type, 'subject_id' => (int) $id];
                foreach (CalendarPermission::RIGHTS as $right) {
                    $row[$right] = $readOnly && in_array($right, self::WRITE_RIGHTS, true)
                        ? !empty($kept[$type . ':' . (int) $id][$right])
                        : is_array($rights) && !empty($rights[$right]);
                }
                unset($kept[$type . ':' . (int) $id]);
                if (!array_filter(array_intersect_key($row, array_flip(CalendarPermission::RIGHTS)))) {
                    continue;
                }
                if (!ctype_digit((string) $id) || !self::subjectExists($type, (int) $id)) {
                    $errors["permissions.$type.$id"] = __('This user, team or mailbox does not exist.');
                    continue;
                }
                $rows[] = $row;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        // Stored write rights of subjects the form now leaves without Dashboard and View, while those still exist.
        foreach ($kept as $key => $rights) {
            [$type, $id] = explode(':', $key, 2);
            if (self::subjectExists($type, (int) $id)) {
                $rows[] = ['subject_type' => $type, 'subject_id' => (int) $id, 'show_in_dashboard' => false, 'can_view' => false] + $rights;
            }
        }

        return $rows;
    }

    /** @return array "<type>:<id>" => [can_create, can_edit] of the stored rows that have a write right */
    private static function storedWriteRights(Calendar $calendar): array
    {
        $kept = [];
        foreach (CalendarPermission::where('calendar_id', $calendar->id)->whereIn('subject_type', self::editableSubjectTypes())->get() as $row) {
            $rights = ['can_create' => (bool) $row->can_create, 'can_edit' => (bool) $row->can_edit];
            if (array_filter($rights)) {
                $kept[$row->subject_type . ':' . $row->subject_id] = $rights;
            }
        }

        return $kept;
    }

    private static function subjectExists(string $type, int $id): bool
    {
        if ($type === CalendarPermission::SUBJECT_MAILBOX) {
            return Mailbox::where('id', $id)->exists();
        }
        if ($type === CalendarPermission::SUBJECT_TEAM) {
            return CalendarPolicy::team($id) !== null;
        }

        return User::where('id', $id)->where('type', User::TYPE_USER)->where('status', '!=', User::STATUS_DELETED)->exists();
    }
}
