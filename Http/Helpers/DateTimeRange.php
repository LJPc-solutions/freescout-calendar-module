<?php

namespace Modules\LJPcCalendarModule\Http\Helpers;

use DateTimeImmutable;

class DateTimeRange {
		public static function isAllDay( DateTimeImmutable $start, DateTimeImmutable $end ): bool {
				if ( $start === $end ) {
						return true;
				}

				$diff = $end->getTimestamp() - $start->getTimestamp();

				return $diff > 86280;


		}
}
