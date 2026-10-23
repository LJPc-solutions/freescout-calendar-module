<?php

namespace Modules\LJPcCalendarModule\Tests\Support;

use Illuminate\Support\Facades\Log;

/** Replaces the logger and keeps every record. Core's Mockery (1.1) can't build a Log spy on PHP 8.3. */
class LogRecorder
{
    /** @var array[] one ['level' => string, 'message' => string, 'context' => array] per record */
    private array $records = [];

    /** Binds a new recorder as the logger (the Log facade, report() and logger()) and returns it. */
    public static function install(): self
    {
        $recorder = new self();
        Log::swap($recorder);

        return $recorder;
    }

    public function __call(string $level, array $arguments): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) ($arguments[0] ?? ''), 'context' => (array) ($arguments[1] ?? [])];
    }

    /** The records of one level, or all of them. */
    public function records(?string $level = null): array
    {
        return array_values(array_filter($this->records, function (array $record) use ($level) {
            return $level === null || $record['level'] === $level;
        }));
    }
}
