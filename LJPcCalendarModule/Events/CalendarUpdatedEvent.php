<?php

namespace Modules\LJPcCalendarModule\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class CalendarUpdatedEvent implements ShouldBroadcastNow {
	public function broadcastOn() {
		return new Channel( 'calendar' );
	}
}