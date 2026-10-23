<?php

namespace Modules\LJPcCalendarModule\Jobs;

use App\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Modules\LJPcCalendarModule\Entities\CalendarEvent;
use Modules\LJPcCalendarModule\Mail\ReminderMail;

/** One reminder (ReminderService), sent as core sends mail to agents: the system mail settings, the agent's language. */
final class SendReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $timeout = 120;

    public int $userId;

    /** @var array{event_id: int, recurrence_id: ?string, summary: ?string, starts_at: string, ends_at: string, all_day: bool} */
    public array $occurrence;

    public function __construct(int $userId, array $occurrence)
    {
        $this->userId = $userId;
        $this->occurrence = $occurrence;
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        $event = CalendarEvent::find($this->occurrence['event_id']);
        if (!$user || !$event) {
            return; // deleted since the scan
        }
        \MailHelper::setSystemMailDriver();
        app()->setLocale($user->getLocale());
        Mail::to([['name' => $user->getFullName(), 'email' => $user->email]])->send(new ReminderMail($user, $event, $this->occurrence));
    }
}
