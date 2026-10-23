<?php

namespace Modules\LJPcCalendarModule\Http\Middleware;

use Closure;
use Modules\LJPcCalendarModule\Services\MigrationService;

/** Keeps the agent API closed until the v2 to v3 data migration has finished. */
class EnsureCalendarMigrated
{
    public function handle($request, Closure $next)
    {
        if (!app(MigrationService::class)->isFinished()) {
            return response()->json(['message' => __('The calendar is being upgraded. An administrator can find the report under Settings > Calendar.')], 503);
        }

        return $next($request);
    }
}
