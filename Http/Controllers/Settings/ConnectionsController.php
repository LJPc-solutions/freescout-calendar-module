<?php

namespace Modules\LJPcCalendarModule\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavClient;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\ProviderRegistry;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\CalendarForm;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\CalendarPicker;
use Modules\LJPcCalendarModule\Services\ConnectionService;

/**
 * Form actions of the Connections tab in Settings > Calendar. Flash messages that carry an account or calendar name
 * are escaped here, because FreeScout prints floating flashes as HTML.
 */
class ConnectionsController extends Controller
{
    /**
     * The Google or Microsoft app (client id, secret and for Microsoft the tenant and the secret's expiry date). Errors go
     * to a bag named after the provider and the old input carries "app" => provider, so only that app's form shows them;
     * the secret is never flashed back into the session.
     */
    public function saveApp(Request $request, ConnectionService $connections, $provider)
    {
        try {
            $connections->saveAppCredentials($provider, $request->only(['client_id', 'client_secret', 'tenant', 'secret_expires_at']));
        } catch (ValidationException $e) {
            return redirect(SettingsPage::connectionsUrl())->withErrors($e->errors(), $provider)->withInput(['app' => $provider] + $request->except('client_secret'));
        }
        $message = $provider === 'google' ? __('Google app saved.') : __('Microsoft app saved.');

        return redirect(SettingsPage::connectionsUrl())->with('flash_success_floating', $message);
    }

    /**
     * Connects a CalDAV account, or with "connection" logs an existing one in again (an empty password keeps the saved
     * one). The login is stored only after discovery with it found the account's calendars; then the pick screen opens.
     * Connecting the same server (however its address is written) and username again updates that connection, also
     * when a second post of the form saved it while discovery ran. Errors go to the bag "caldav"; the old input carries
     * "connection", so only the form that was posted shows them, and never the password.
     */
    public function caldav(Request $request, ProviderRegistry $providers)
    {
        $existing = null;
        $id = $request->input('connection');
        if ($id !== null) {
            $existing = is_string($id) && ctype_digit($id) ? CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->find((int) $id) : null;
            abort_unless($existing !== null, 404);
        }
        $back = $existing ? SettingsPage::accountUrl($existing) : SettingsPage::connectionsUrl() . '#ljpc-caldav';
        if (is_string($request->input('server_url'))) {
            $request->merge(['server_url' => trim($request->input('server_url'))]);
        }
        // Basic authentication can't carry a colon in the username (RFC 7617), and Guzzle refuses to send one.
        $rules = ['username' => ['required', 'string', 'max:191', 'regex:/^[^:]*$/'], 'password' => ($existing ? 'nullable' : 'required') . '|string|max:500'];
        if (!$existing) {
            $rules['server_url'] = ['bail', 'required', 'string', 'max:2000', 'regex:~^https?://[^\s/?#]+~i'];
        }
        $validator = Validator::make($request->all(), $rules, [
            'server_url.regex' => __('Enter an address that starts with https:// or http://.'),
            'username.regex'   => __("The username can't contain a colon."),
        ]);
        // The login belongs in its own, encrypted fields: an address is stored and shown as it is.
        $validator->after(function ($validator) use ($request) {
            $url = $request->input('server_url');
            if (is_string($url) && !$validator->errors()->has('server_url') && CalendarForm::hasLogin($url)) {
                $validator->errors()->add('server_url', __('Leave the username and password out of the address and fill them in below.'));
            }
        });
        if ($validator->fails()) {
            return redirect($back)->withErrors($validator, 'caldav')->withInput($request->except('password'));
        }

        $username = (string) $request->input('username');
        $password = (string) $request->input('password');
        // One connection per account on a server: connecting the same account again updates it, and so must this.
        if ($existing && $username !== $existing->account && self::sameAccount((string) $existing->server_url, $username, $existing->id)) {
            return redirect($back)->withErrors(['connect' => __(':account is already connected.', ['account' => $username])], 'caldav')->withInput($request->except('password'));
        }
        $connection = $existing ?? self::sameAccount($request->input('server_url'), $username)
            ?? new CalendarConnection(['provider' => Calendar::PROVIDER_CALDAV, 'server_url' => $request->input('server_url'), 'created_by' => $request->user()->id]);
        if ($password === '') {
            $password = (string) ($connection->credentials()['password'] ?? '');
        }
        $connection->account = $username;
        $connection->setCredentials(['username' => $username, 'password' => $password]);
        try {
            $providers->forProvider(Calendar::PROVIDER_CALDAV)->listCalendars($connection);
        } catch (ProviderException $e) {
            return redirect($back)->withErrors(['connect' => $e->userMessage()], 'caldav')->withInput($request->except('password'));
        }
        // A sync whose old password was refused while discovery ran may have marked the row needs_reauth; Eloquent saves
        // no status it loaded as ok, so it is written again, in the same transaction as the new login.
        $connection = DB::transaction(function () use ($connection, $username, $password) {
            if (!$connection->exists) {
                // A second post of this form (a double-click) may have connected the account while discovery ran; the
                // lock makes the other post wait for this one.
                $saved = self::sameAccount((string) $connection->server_url, $username, null, true);
                if ($saved !== null) {
                    $saved->account = $username;
                    $saved->setCredentials(['username' => $username, 'password' => $password]);
                    $connection = $saved;
                }
            }
            $connection->status = CalendarConnection::STATUS_OK;
            $connection->last_error = null;
            $connection->save();
            CalendarConnection::whereKey($connection->id)->update(['status' => CalendarConnection::STATUS_OK, 'last_error' => null]);
            $connection->resumeCalendars();

            return $connection;
        });

        return redirect(SettingsPage::connectionsUrl($connection->id))
            ->with('flash_success_floating', e(__(':account is connected. Choose the calendars FreeScout shows.', ['account' => $connection->account])));
    }

    /** The CalDAV connection of this username on this server, whether or not its address ends in a slash or differs in case. */
    private static function sameAccount(string $serverUrl, string $username, ?int $except = null, bool $lock = false): ?CalendarConnection
    {
        $query = CalendarConnection::where('provider', Calendar::PROVIDER_CALDAV)->where('account', $username);
        if ($except !== null) {
            $query->whereKeyNot($except);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('id')->get()->first(function (CalendarConnection $connection) use ($serverUrl) {
            return CalDavClient::sameResource((string) $connection->server_url, $serverUrl);
        });
    }

    /** Adds the calendars ticked on the pick screen ("owner" names a Microsoft shared mailbox) and opens the calendar list. */
    public function addCalendars(Request $request, CalendarPicker $picker, $id)
    {
        $connection = CalendarConnection::findOrFail($id);
        $owner = $connection->provider === Calendar::PROVIDER_MICROSOFT && is_string($request->input('owner')) && trim($request->input('owner')) !== ''
            ? mb_strtolower(trim($request->input('owner')))
            : null;
        $back = redirect(SettingsPage::connectionsUrl($connection->id, $owner));
        $validator = Validator::make(['owner' => $owner] + $request->only('calendars'), [
            'owner'       => 'nullable|email|max:191',
            'calendars'   => 'required|array',
            'calendars.*' => 'string|max:2000',
        ], [
            'owner.email'        => __('Enter the email address of the shared mailbox.'),
            'calendars.required' => __('Choose at least one calendar.'),
        ]);
        if ($validator->fails()) {
            return $back->withErrors($validator)->withInput();
        }
        try {
            $added = $picker->add($connection, $owner, array_values($request->input('calendars')));
        } catch (ProviderException $e) {
            return $back->withErrors(['calendars' => $e->userMessage()])->withInput();
        }
        if (!$added) {
            return $back->withErrors(['calendars' => __('These calendars are already added.')]);
        }
        $names = implode(', ', array_map(function (Calendar $calendar) {
            return '"' . $calendar->name . '"';
        }, $added));

        // The list starts their first sync right away and shows it live (SettingsPage::syncStartIds()).
        return redirect(SettingsPage::url())
            ->with('ljpccalendarmodule.sync_start', array_map(function (Calendar $calendar) {
                return $calendar->id;
            }, $added))
            ->with('flash_success_floating', e(__('Added :names. Open each calendar to choose who can use it.', ['names' => $names])));
    }

    /** Deletes the connection with its calendars (their events and occurrences cascade); nothing is deleted at the provider. */
    public function destroy($id)
    {
        $connection = CalendarConnection::findOrFail($id);
        DB::transaction(function () use ($connection) {
            Calendar::where('connection_id', $connection->id)->delete();
            $connection->delete();
        });

        return redirect(SettingsPage::connectionsUrl())->with('flash_success_floating', __('The connection and its calendars were deleted.'));
    }
}
