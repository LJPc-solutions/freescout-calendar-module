<?php

namespace Modules\LJPcCalendarModule\Http;

/** The sentence someone reads after "Sync now" for one calendar (a result of SyncService::syncByHand()). */
final class SyncReport
{
    private const ERRORS = ['failed', 'off', 'reauth'];

    /** $error is the calendar's last_error for administrators, null for agents: they never read it. */
    public static function message(array $result, ?string $error): string
    {
        $counts = ['created' => $result['created'], 'updated' => $result['updated'], 'deleted' => $result['deleted']];
        switch ($result['state']) {
            case 'synced':
                return array_sum($counts) === 0
                    ? __('Synced. Nothing changed.')
                    : __('Synced: :created new, :updated changed, :deleted removed.', $counts);
            case 'partial':
                return __('Partly synced: :created new, :updated changed, :deleted removed. The rest follows within a few minutes.', $counts);
            case 'running':
                return __('This calendar is syncing right now. It finishes within a minute.');
            case 'recent':
                return __('This calendar synced moments ago.');
            case 'queued':
                return __('This calendar syncs within a minute.');
            case 'off':
                return __('This calendar is off, so it doesn\'t sync. Turn it on first.');
            case 'reauth':
                return __('The account of this calendar must be connected again before it can sync.');
            default:
                return trim((string) $error) !== ''
                    ? __('Syncing failed: :error', ['error' => $error])
                    : __('This calendar could not be synced right now.');
        }
    }

    public static function isError(array $result): bool
    {
        return in_array($result['state'], self::ERRORS, true);
    }
}
