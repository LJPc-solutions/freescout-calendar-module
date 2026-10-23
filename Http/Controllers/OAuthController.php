<?php

namespace Modules\LJPcCalendarModule\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Http\SettingsPage;
use Modules\LJPcCalendarModule\Services\ConnectionService;

/**
 * Connects a Google or Microsoft account: start sends the administrator to the provider, which sends them back to
 * callback. Flash messages are escaped here, because FreeScout prints them as HTML and they can hold the provider's
 * words or the account's address.
 */
class OAuthController extends Controller
{
    /** ?connection=<id> reauthorizes that connection, which must belong to this provider. */
    public function start(Request $request, ConnectionService $connections, $provider)
    {
        $reauthorize = null;
        $id = $request->query('connection');
        if ($id !== null) {
            $reauthorize = is_string($id) && ctype_digit($id) ? CalendarConnection::where('provider', $provider)->find((int) $id) : null;
            abort_unless($reauthorize !== null, 404);
        }
        try {
            return redirect()->away($connections->authorizationUrl($provider, $reauthorize));
        } catch (ValidationException $e) {
            return redirect(SettingsPage::connectionsUrl())->with('flash_error_floating', e(self::firstError($e)));
        }
    }

    public function callback(Request $request, ConnectionService $connections, $provider)
    {
        try {
            $connection = $connections->completeAuthorization($provider, $request);
        } catch (ValidationException $e) {
            return redirect(SettingsPage::connectionsUrl())->with('flash_error_floating', e(self::firstError($e)));
        } catch (ProviderException $e) {
            return redirect(SettingsPage::connectionsUrl())->with('flash_error_floating', e($e->userMessage()));
        }

        return redirect(SettingsPage::connectionsUrl($connection->id))
            ->with('flash_success_floating', e(__(':account is connected. Choose the calendars FreeScout shows.', ['account' => $connection->account])));
    }

    private static function firstError(ValidationException $e): string
    {
        return (string) collect($e->errors())->flatten()->first();
    }
}
