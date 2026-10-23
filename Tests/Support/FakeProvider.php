<?php

namespace Modules\LJPcCalendarModule\Tests\Support;

use LogicException;
use Modules\LJPcCalendarModule\CalendarProviders\CalendarProvider;
use Modules\LJPcCalendarModule\CalendarProviders\ChangeSet;
use Modules\LJPcCalendarModule\CalendarProviders\RemoteRef;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Entities\CalendarConnection;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * A provider whose answers a test scripts. Each pull() and write takes the next scripted result for that method:
 * a value to return, a Throwable to throw, or a callable that gets the call's arguments and returns or throws.
 * Without a scripted result, pull() fails the test and writes behave like LocalProvider. Every call is logged.
 */
class FakeProvider implements CalendarProvider
{
    public bool $canWrite;

    public bool $writableRecurrence = true;

    /** @var \Modules\LJPcCalendarModule\CalendarProviders\RemoteCalendar[] */
    public array $calendars = [];

    /** Thrown by listCalendars() instead of returning $calendars, when set. */
    public ?\Throwable $listError = null;

    /** @var array[] one ['method' => string, ...the call's arguments] per call, in order */
    public array $calls = [];

    private array $scripts = ['pull' => [], 'create' => [], 'update' => [], 'delete' => []];

    public function __construct(bool $canWrite = true)
    {
        $this->canWrite = $canWrite;
    }

    /** @param ChangeSet|\Throwable|callable ...$results */
    public function onPull(...$results): self
    {
        return $this->script('pull', $results);
    }

    /** @param RemoteRef|\Throwable|callable ...$results */
    public function onCreate(...$results): self
    {
        return $this->script('create', $results);
    }

    /** @param RemoteRef|\Throwable|callable ...$results */
    public function onUpdate(...$results): self
    {
        return $this->script('update', $results);
    }

    /** @param null|\Throwable|callable ...$results */
    public function onDelete(...$results): self
    {
        return $this->script('delete', $results);
    }

    /** The logged calls of one method. */
    public function calls(string $method): array
    {
        return array_values(array_filter($this->calls, function (array $call) use ($method) {
            return $call['method'] === $method;
        }));
    }

    public function canWrite(): bool
    {
        return $this->canWrite;
    }

    public function writableRecurrence(VEvent $master): bool
    {
        return $this->writableRecurrence;
    }

    public function listCalendars(CalendarConnection $connection, ?string $owner = null): array
    {
        $this->calls[] = ['method' => 'listCalendars', 'connection_id' => $connection->id, 'owner' => $owner];
        if ($this->listError !== null) {
            throw $this->listError;
        }

        return $this->calendars;
    }

    public function pull(Calendar $calendar, array $state, SyncBudget $budget): ChangeSet
    {
        $this->calls[] = ['method' => 'pull', 'calendar_id' => $calendar->id, 'state' => $state];
        if (!$this->scripts['pull']) {
            throw new LogicException('FakeProvider: no pull result was scripted for calendar ' . $calendar->id);
        }

        return $this->next('pull', [$calendar, $state, $budget]);
    }

    public function create(Calendar $calendar, VCalendar $vcal): RemoteRef
    {
        $this->calls[] = ['method' => 'create', 'calendar_id' => $calendar->id, 'ics' => $vcal->serialize()];

        return $this->scripts['create'] ? $this->next('create', [$calendar, $vcal]) : new RemoteRef('');
    }

    public function update(Calendar $calendar, CalendarEvent $event, VCalendar $vcal, ?string $occurrenceKey = null): RemoteRef
    {
        $this->calls[] = ['method' => 'update', 'calendar_id' => $calendar->id, 'event_id' => $event->id, 'ics' => $vcal->serialize(), 'occurrence_key' => $occurrenceKey];

        return $this->scripts['update'] ? $this->next('update', [$calendar, $event, $vcal, $occurrenceKey]) : new RemoteRef('');
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $this->calls[] = ['method' => 'delete', 'calendar_id' => $calendar->id, 'event_id' => $event->id];
        if ($this->scripts['delete']) {
            $this->next('delete', [$calendar, $event]);
        }
    }

    private function script(string $method, array $results): self
    {
        array_push($this->scripts[$method], ...$results);

        return $this;
    }

    private function next(string $method, array $arguments)
    {
        $result = array_shift($this->scripts[$method]);
        if ($result instanceof \Throwable) {
            throw $result;
        }

        return is_callable($result) ? $result(...$arguments) : $result;
    }
}
