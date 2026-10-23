<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Microsoft;

use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\OAuthApi;

/**
 * Authenticated JSON requests to Microsoft Graph v1.0, as the connection's account (OAuthApi). Every request asks for
 * immutable ids and UTC times (research question 3), and for text bodies, which Graph documents for event GET and
 * may ignore elsewhere; each preference goes in a Prefer header of its own, the form research found safe. Throttling
 * (429, and 503 with or without Retry-After) is retried after Retry-After, else with exponential backoff (throttling
 * guide). Outlook allows 4 concurrent requests per mailbox; these requests are sent one at a time. A path may be an
 * @odata.nextLink, which is only followed on Graph's own address.
 */
class GraphApi extends OAuthApi
{
    public const BASE_URL = 'https://graph.microsoft.com/v1.0';
    public const PREFER = ['IdType="ImmutableId"', 'outlook.timezone="UTC"', 'outlook.body-content-type="text"'];

    protected function provider(): string
    {
        return 'microsoft';
    }

    protected function failureLog(): string
    {
        return 'A Microsoft Graph request failed';
    }

    protected function url(string $path): string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $path)) {
            // A next link Graph handed out: the token only goes to Graph v1.0 itself.
            if (strpos($path, self::BASE_URL . '/') !== 0) {
                throw new ProviderUnavailable(__('Microsoft Graph pointed to an address outside Graph. FreeScout did not follow it.'));
            }

            return $path;
        }

        return self::BASE_URL . '/' . ltrim($path, '/');
    }

    protected function headers(): array
    {
        return ['Prefer' => self::PREFER];
    }

    protected function isRateLimit(int $status, array $json): bool
    {
        return $status === 429 || $status === 503;
    }

    protected function failure(int $status, array $json): ProviderException
    {
        $code = is_string($json['error']['code'] ?? null) ? $json['error']['code'] : '';
        if ($status === 401) {
            return new ConnectionNeedsReauth(401);
        }
        if ($status === 412) {
            return new ProviderConflict(412); // ErrorIrresolvableConflict: the change key moved on
        }
        if ($status === 429) {
            return new ProviderUnavailable(__('Microsoft is limiting requests right now. Try again in a minute.'), 429);
        }
        if ($status === 403) {
            return new ProviderUnavailable(__('Microsoft refused this request. The account may not have access to this calendar, or may only read it.'), 403);
        }
        if ($status === 404) {
            return new ProviderUnavailable(__('Microsoft could not find this calendar or event. It may have been deleted, or the account no longer has access to it.'), 404);
        }
        if ($status === 400 && $code === 'ErrorOccurrenceCrossingBoundary') {
            return new ProviderUnavailable(__('Microsoft does not let an occurrence move onto or past the day of the occurrence before or after it.'), 400);
        }
        if ($status === 400) {
            return new ProviderUnavailable(__('Microsoft did not accept the event as FreeScout sent it.'), 400);
        }

        return new ProviderUnavailable(null, $status);
    }
}
