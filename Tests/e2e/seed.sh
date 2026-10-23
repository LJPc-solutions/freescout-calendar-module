#!/usr/bin/env bash
# Prepares the local ddev FreeScout for the end-to-end suite (Resources/frontend/e2e). It creates users with a known
# password, so it only runs inside the local ddev project:
#   ddev exec bash Modules/freescout-calendar-module/Tests/e2e/seed.sh
# Run it before every run of the suite: the suite leaves its events behind, and it refuses a seed it has used already.
# The CalDAV and ICS specs need Radicale (Tests/caldav/install.sh) and its line in FreeScout's .env.
set -euo pipefail
if [ "${DDEV_PROJECT:-}" != "freescout" ]; then
    echo "Run this inside the local ddev project only." >&2
    exit 1
fi
cd /var/www/html
php artisan config:clear >/dev/null
frontend=Modules/freescout-calendar-module/Resources/frontend

# Radicale: the e2e collections start empty on every seed. The feed collection is readable without a login
# (Tests/caldav/rights), because an ICS feed has none, and holds one event a week from today at 10:00 in Amsterdam.
radicale() {
    curl --silent --output /dev/null --write-out '%{http_code}' --user calendar-test:calendar-test "$@"
}
if [ "$(radicale --request PROPFIND --header 'Depth: 0' http://radicale:5232/calendar-test/ || true)" != "207" ]; then
    echo "Radicale does not answer at http://radicale:5232. Add it with: bash Modules/freescout-calendar-module/Tests/caldav/install.sh" >&2
    exit 1
fi
allowed=$(php artisan tinker <<'PHP'
try {
    app(Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory::class)->assertAllowed('http://radicale:5232/');
    echo "radicale allowed\n";
} catch (Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException $e) {
    echo $e->userMessage(), "\n";
}
PHP
)
if ! grep -q "radicale allowed" <<<"$allowed"; then
    echo "FreeScout may not reach Radicale. Add the line Tests/caldav/install.sh prints (APP_REMOTE_HOST_WHITE_LIST=radicale,172.16.0.0/12)" >&2
    echo "to FreeScout's .env, then run this seed again." >&2
    exit 1
fi
collection() {
    radicale --request DELETE "http://radicale:5232/calendar-test/$1/" >/dev/null || true
    local status
    status=$(radicale --request MKCALENDAR --header 'Content-Type: application/xml; charset=utf-8' \
        --data "<?xml version=\"1.0\" encoding=\"utf-8\"?><C:mkcalendar xmlns:D=\"DAV:\" xmlns:C=\"urn:ietf:params:xml:ns:caldav\"><D:set><D:prop><D:displayname>$2</D:displayname></D:prop></D:set></C:mkcalendar>" \
        "http://radicale:5232/calendar-test/$1/")
    if [ "$status" != "201" ]; then
        echo "Creating the Radicale calendar $1 failed with HTTP $status." >&2
        exit 1
    fi
}
collection e2e "E2E CalDAV"
collection e2e-connect "E2E connect"
collection e2e-feed "E2E feed"
feed_date=$(TZ=Europe/Amsterdam date -d '+7 days' +%Y-%m-%d)
day=${feed_date//-/}
feed_event=$(printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//LJPc//FreeScout calendar e2e//EN\r\nBEGIN:VEVENT\r\nUID:e2e-feed-event@example.com\r\nDTSTAMP:20261001T000000Z\r\nDTSTART;TZID=Europe/Amsterdam:%sT100000\r\nDTEND;TZID=Europe/Amsterdam:%sT110000\r\nSUMMARY:E2E feed event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' "$day" "$day")
status=$(radicale --request PUT --header 'Content-Type: text/calendar; charset=utf-8' --data-binary "$feed_event" http://radicale:5232/calendar-test/e2e-feed/e2e-feed-event.ics)
if [ "$status" != "201" ]; then
    echo "Adding the feed's event to Radicale failed with HTTP $status." >&2
    exit 1
fi

# Tinker runs each complete line on its own and carries on after an error. The closure makes it one statement, so
# any failure stops the seed before it prints "e2e seed ready".
output=$(E2E_FEED_DATE="$feed_date" php artisan tinker <<'PHP'
use App\Attachment;
use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Option;
use App\Thread;
use App\User;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Entities\CalendarInvite;
use Modules\LJPcCalendarModule\Entities\CalendarPermission;
use Modules\LJPcCalendarModule\Services\EventService;
use Modules\LJPcCalendarModule\Services\HealthService;
use Modules\LJPcCalendarModule\Services\ItipMail;

(function () {
    $password = 'e2e-calendar-pass';
    $person = function (string $email, string $lastName, int $role, string $timezone, int $clock) use ($password) {
        $user = User::where('email', $email)->first() ?: new User();
        $user->forceFill([
            'email' => $email, 'first_name' => 'E2E', 'last_name' => $lastName, 'type' => User::TYPE_USER, 'status' => User::STATUS_ACTIVE,
            'role' => $role, 'timezone' => $timezone, 'time_format' => $clock, 'locale' => 'en', 'password' => \Hash::make($password),
        ]);
        $user->save();

        return $user;
    };
    $admin = $person('e2e-admin@example.test', 'Admin', User::ROLE_ADMIN, 'Europe/Amsterdam', User::TIME_FORMAT_24);
    $agent = $person('e2e-agent@example.test', 'Agent', User::ROLE_USER, 'America/New_York', User::TIME_FORMAT_12);
    // The agent's own rows start over, so the agent sees the calendars below. The agent is in no team, and the only
    // mailbox it has gets no calendar rows (below).
    CalendarPermission::where('subject_type', CalendarPermission::SUBJECT_USER)->where('subject_id', $agent->id)->delete();

    $mailbox = Mailbox::where('email', 'e2e-mailbox@example.test')->first()
        ?: factory(Mailbox::class)->create(['name' => 'E2E mailbox', 'email' => 'e2e-mailbox@example.test']);
    // What FreeScout's mailbox permissions screen does: access plus the agent's personal folders.
    $mailbox->users()->syncWithoutDetaching([$agent->id]);
    $mailbox->syncPersonalFolders();
    CalendarPermission::where('subject_type', CalendarPermission::SUBJECT_MAILBOX)->where('subject_id', $mailbox->id)->delete();
    $customer = Customer::create('e2e-customer@example.test', ['first_name' => 'E2E', 'last_name' => 'Customer']);
    $conversation = Conversation::where('mailbox_id', $mailbox->id)->where('subject', 'E2E printer is broken')->first();
    if (!$conversation) {
        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id, 'customer_email' => 'e2e-customer@example.test',
            'subject' => 'E2E printer is broken', 'status' => Conversation::STATUS_ACTIVE, 'type' => Conversation::TYPE_EMAIL,
            'user_id' => $agent->id, 'created_by_user_id' => $admin->id,
        ]);
        factory(Thread::class)->create([
            'conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'to' => 'e2e-mailbox@example.test',
            'body' => 'The printer on the second floor is broken.',
        ]);
        $conversation->updateFolder();
        $conversation->save();
    }
    // "Added to the calendar" line items and the invites the suite sent in earlier runs.
    Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_LINEITEM)
        ->where('action_type', CalendarEvent::ACTION_TYPE_ADD_TO_CALENDAR)->delete();
    Thread::where('conversation_id', $conversation->id)->where('type', Thread::TYPE_MESSAGE)->get()->each(function (Thread $sent) {
        if ($sent->getMeta(ItipMail::META) !== null) {
            Attachment::deleteAttachments($sent->attachments);
            $sent->delete();
        }
    });

    // The invites spec: a conversation of its own, new on every seed, whose customer's mail holds an invite for the
    // mailbox a week from today at 15:00 in Amsterdam.
    Conversation::where('mailbox_id', $mailbox->id)->where('subject', 'E2E invite')->get()->each(function (Conversation $old) {
        CalendarInvite::where('conversation_id', $old->id)->delete();
        $old->deleteForever();
    });
    $inviteConversation = factory(Conversation::class)->create([
        'mailbox_id' => $mailbox->id, 'customer_id' => $customer->id, 'customer_email' => 'e2e-customer@example.test',
        'subject' => 'E2E invite', 'status' => Conversation::STATUS_ACTIVE, 'type' => Conversation::TYPE_EMAIL,
        'user_id' => $agent->id, 'created_by_user_id' => $admin->id,
    ]);
    $inviteThread = factory(Thread::class)->create([
        'conversation_id' => $inviteConversation->id, 'customer_id' => $customer->id, 'type' => Thread::TYPE_CUSTOMER,
        'from' => 'e2e-customer@example.test', 'to' => 'e2e-mailbox@example.test', 'has_attachments' => true, 'body' => 'Please join the planning call.',
    ]);
    $inviteDay = str_replace('-', '', getenv('E2E_FEED_DATE'));
    Attachment::create('invite.ics', 'text/calendar', Attachment::TYPE_TEXT, implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LJPc//FreeScout calendar e2e//EN', 'METHOD:REQUEST', 'BEGIN:VEVENT',
        'UID:e2e-invite-' . bin2hex(random_bytes(6)) . '@example.test', 'DTSTAMP:20261001T000000Z', 'SEQUENCE:0',
        'DTSTART;TZID=Europe/Amsterdam:' . $inviteDay . 'T150000', 'DTEND;TZID=Europe/Amsterdam:' . $inviteDay . 'T160000',
        'SUMMARY:E2E planning call', 'LOCATION:Online', 'ORGANIZER;CN=E2E Customer:mailto:e2e-customer@example.test',
        'ATTENDEE;PARTSTAT=ACCEPTED;CN=E2E Customer:mailto:e2e-customer@example.test',
        'ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:e2e-mailbox@example.test',
        'END:VEVENT', 'END:VCALENDAR', '',
    ]), null, false, $inviteThread->id);
    $inviteConversation->updateFolder();
    $inviteConversation->save();

    $calendar = function (string $name, array $attributes, array $rights) use ($agent) {
        $calendar = Calendar::where('name', $name)->first() ?: Calendar::create(['name' => $name, 'color' => '#2ecc71'] + $attributes);
        $calendar->update(['enabled' => true, 'title_template' => null] + $attributes);
        CalendarEvent::where('calendar_id', $calendar->id)->delete();
        CalendarPermission::where('calendar_id', $calendar->id)->delete();
        CalendarPermission::create(['calendar_id' => $calendar->id, 'subject_type' => 'user', 'subject_id' => $agent->id] + $rights);

        return $calendar;
    };
    $all = ['show_in_dashboard' => true, 'can_view' => true, 'can_create' => true, 'can_edit' => true];
    $support = $calendar('E2E Support', ['provider' => Calendar::PROVIDER_LOCAL, 'fields' => [
        ['id' => 172604180001, 'name' => 'Kind', 'type' => 'dropdown', 'required' => false, 'options' => ['Call', 'Visit']],
        ['id' => 172604180002, 'name' => 'Paid', 'type' => 'boolean', 'required' => false, 'options' => []],
        ['id' => 172604180003, 'name' => 'Customer email', 'type' => 'email', 'required' => false, 'options' => []],
    ]], $all);
    $readOnly = $calendar('E2E Read only', ['provider' => Calendar::PROVIDER_LOCAL, 'fields' => []], ['can_view' => true]);
    $fixed = app(EventService::class)->create($readOnly, $admin, ['title' => 'E2E fixed', 'start' => '2026-11-20T12:00']);
    // A series by a rule the form can't show (the second Tuesday of the month): a copy of it never keeps the rule.
    $customUid = 'e2e-custom-' . bin2hex(random_bytes(6)) . '@example.test';
    app(EventService::class)->createFromIcs($support, $admin, \Sabre\VObject\Reader::read(implode("\r\n", [
        'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LJPc//FreeScout calendar e2e//EN', 'BEGIN:VEVENT', 'UID:' . $customUid,
        'DTSTAMP:20261001T000000Z', 'DTSTART;TZID=America/New_York:20261110T100000', 'DTEND;TZID=America/New_York:20261110T110000',
        'RRULE:FREQ=MONTHLY;BYDAY=TU;BYSETPOS=2;COUNT=3', 'SUMMARY:E2E custom rule', 'END:VEVENT', 'END:VCALENDAR', '',
    ])), $customUid, null);

    // A CalDAV account on Radicale with one calendar the agent may change; the conflict spec edits behind its back.
    $dav = CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->where('server_url', 'http://radicale:5232/')->where('account', 'calendar-test')->first()
        ?: new CalendarConnection(['provider' => Calendar::PROVIDER_CALDAV, 'server_url' => 'http://radicale:5232/', 'account' => 'calendar-test', 'created_by' => $admin->id]);
    $dav->setCredentials(['username' => 'calendar-test', 'password' => 'calendar-test']);
    $dav->status = CalendarConnection::STATUS_OK;
    $dav->last_error = null;
    $dav->save();
    $sync = ['sync_state' => null, 'next_sync_at' => null, 'last_synced_at' => null, 'last_error' => null, 'sync_failures' => 0, 'sync_lease_until' => null];
    $calendar('E2E CalDAV', ['provider' => Calendar::PROVIDER_CALDAV, 'connection_id' => $dav->id, 'remote_id' => 'http://radicale:5232/calendar-test/e2e/',
        'remote_read_only' => false, 'timezone' => null, 'fields' => []] + $sync, $all);
    // What the CalDAV and ICS specs add through the settings.
    Calendar::where('remote_id', 'like', 'http://radicale:5232/calendar-test/e2e-connect%')->orWhere('name', 'like', 'E2E connect%')
        ->orWhere('name', 'like', 'E2E feed%')->orWhere('name', 'like', 'E2E settings %')->get()->each->delete();

    // A feed that has failed for two days: the health banners name it. It is not due, so no sync of the suite touches it.
    $failing = $calendar('E2E failing feed', ['provider' => Calendar::PROVIDER_ICS, 'remote_id' => 'http://radicale:5232/calendar-test/e2e-missing/', 'color' => '#e67e22',
        'fields' => []] + $sync, ['can_view' => true]);
    $failing->update([
        'last_error' => 'The feed answered with an error. (HTTP 404)', 'sync_failures' => 9,
        'last_synced_at' => gmdate('Y-m-d H:i:s', time() - 2 * 86400), 'next_sync_at' => gmdate('Y-m-d H:i:s', time() + 365 * 86400),
    ]);
    // The failing feed counts as mailed already, and the suite's syncs check the health no sooner than in 15 minutes;
    // what the dev install's own problems were mailed for (and since when they are solved) stays remembered.
    $health = Option::get(HealthService::OPTION, [], true, false);
    $health = is_array($health) ? $health : [];
    $notified = is_array($health['notified'] ?? null) ? $health['notified'] : [];
    $now = gmdate('Y-m-d\TH:i:s\Z');
    Option::set(HealthService::OPTION, ['checked_at' => $now, 'notified' => ['calendar:' . $failing->id . ':failing' => $now] + $notified] + $health);

    $url = $conversation->url();
    $inviteUrl = $inviteConversation->url();
    $written = file_put_contents(base_path('Modules/freescout-calendar-module/Resources/frontend/e2e/.seed.json'), json_encode([
        'password'           => $password,
        'admin'              => $admin->email,
        'agent'              => $agent->email,
        'conversationPath'   => parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : ''),
        'readOnlyEventId'    => $fixed->id,
        'feedEventDate'      => getenv('E2E_FEED_DATE'),
        'failingCalendarId'  => $failing->id,
        'inviteConversationPath' => parse_url($inviteUrl, PHP_URL_PATH) . (parse_url($inviteUrl, PHP_URL_QUERY) ? '?' . parse_url($inviteUrl, PHP_URL_QUERY) : ''),
    ], JSON_PRETTY_PRINT));
    if ($written === false) {
        throw new \RuntimeException('Could not write e2e/.seed.json.');
    }
    echo "e2e seed ready\n";
})();
PHP
)
echo "$output"
grep -q "e2e seed ready" <<<"$output" || { echo "Seeding failed." >&2; exit 1; }
# The suite marks a seed as used when it starts (e2e/global-setup.ts).
rm -f "$frontend/e2e/.seed-used"
