<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/** The time one sync run may use. A provider that pages checks it between pages. */
final class SyncBudget
{
    private float $deadline;

    private function __construct(float $deadline)
    {
        $this->deadline = $deadline;
    }

    public static function seconds(float $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    public function exhausted(): bool
    {
        return $this->remaining() <= 0.0;
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - microtime(true));
    }
}
