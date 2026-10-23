<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Google;

use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ConnectionNeedsReauth;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderConflict;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderException;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\OAuthApi;

/**
 * Authenticated JSON requests to the Google Calendar API v3, as the connection's account (OAuthApi). Rate limits (403
 * rateLimitExceeded or userRateLimitExceeded, and 429) are retried with exponential backoff and jitter, or after
 * Retry-After (calendar API errors guide); a used-up quota (403 quotaExceeded, or the project's dailyLimitExceeded) is
 * never handed to a caller either.
 */
class GoogleApi extends OAuthApi
{
    public const BASE_URL = 'https://www.googleapis.com/calendar/v3';

    private const RATE_LIMITS = ['rateLimitExceeded', 'userRateLimitExceeded'];
    /** Usage limits that a wait of seconds doesn't lift: the account's quota, and the project's daily limit. */
    private const QUOTAS = ['quotaExceeded', 'dailyLimitExceeded'];

    protected function provider(): string
    {
        return 'google';
    }

    protected function failureLog(): string
    {
        return 'A Google Calendar request failed';
    }

    protected function url(string $path): string
    {
        return self::BASE_URL . '/' . ltrim($path, '/');
    }

    protected function isRateLimit(int $status, array $json): bool
    {
        return $status === 429 || ($status === 403 && array_intersect(self::reasons($json), self::RATE_LIMITS));
    }

    /** A rate limit, or Google's usage limits used up (403 quotaExceeded or dailyLimitExceeded). */
    protected function isLimited(int $status, array $json): bool
    {
        return $this->isRateLimit($status, $json) || ($status === 403 && array_intersect(self::reasons($json), self::QUOTAS));
    }

    protected function failure(int $status, array $json): ProviderException
    {
        $reasons = self::reasons($json);
        if ($status === 401) {
            return new ConnectionNeedsReauth(401);
        }
        if ($status === 412) {
            return new ProviderConflict(412);
        }
        if ($this->isRateLimit($status, $json)) {
            return new ProviderUnavailable(__('Google is limiting requests. The next sync tries again.'), $status);
        }
        // dailyLimitExceeded is the limit of the Google Cloud project behind the OAuth app, shared by every account.
        if ($status === 403 && in_array('dailyLimitExceeded', $reasons, true)) {
            return new ProviderUnavailable(__("The daily usage limit of the Google Cloud project behind this connection is used up. Syncing continues once Google allows it again; the project's quotas are in the Google Cloud console."), 403);
        }
        if ($status === 403 && array_intersect($reasons, self::QUOTAS)) {
            return new ProviderUnavailable(__("Google's usage limits for this account are used up. Syncing continues once Google allows it again."), 403);
        }
        if ($status === 403 && in_array('forbiddenForNonOrganizer', $reasons, true)) {
            return new ProviderUnavailable(__('Only the organizer can change this event in Google.'), 403);
        }
        if ($status === 403) {
            return new ProviderUnavailable(__('Google refused this request. The account may not have access to this calendar, or may only read it.'), 403);
        }
        if ($status === 404) {
            return new ProviderUnavailable(__('Google could not find this calendar or event. It may have been deleted, or the account no longer has access to it.'), 404);
        }
        if ($status === 400) {
            return new ProviderUnavailable(__('Google did not accept the event as FreeScout sent it.'), 400);
        }
        if ($status === 409) {
            return new ProviderUnavailable(__('Google already has this event in the calendar.'), 409);
        }

        return new ProviderUnavailable(null, $status);
    }

    /** The reason of every error in Google's error body. */
    private static function reasons(array $json): array
    {
        $reasons = [];
        foreach ((array) ($json['error']['errors'] ?? []) as $error) {
            if (is_array($error) && is_string($error['reason'] ?? null)) {
                $reasons[] = $error['reason'];
            }
        }

        return $reasons;
    }
}
