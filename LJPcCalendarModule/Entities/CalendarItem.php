<?php

namespace Modules\LJPcCalendarModule\Entities;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JsonSerializable;

class CalendarItem extends Model implements JsonSerializable {
		public const ACTION_TYPE_ADD_TO_CALENDAR = 186;

		protected $table = 'calendar_items';
		protected $fillable = [
				'calendar_id',
				'author_id',
				'is_all_day',
				'is_private',
				'is_read_only',
				'title',
				'body',
				'state',
				'location',
				'start',
				'end',
		];

		public function calendar(): BelongsTo {
				return $this->belongsTo( Calendar::class );
		}

		public function jsonSerialize(): array {
				$retArr = [
						'id'         => $this->id,
						'title'      => $this->title,
						'location'   => $this->location,
						'body'       => $this->body,
						'state'      => $this->state,
						'start'      => $this->start,
						'end'        => $this->end,
						'isAllDay'   => (int) $this->is_all_day,
						'category'   => $this->is_all_day ? 'allday' : 'time',
						'isPrivate'  => (int) $this->is_private,
						'isReadOnly' => (int) $this->is_read_only,
						'calendarId' => (int) $this->calendar_id,
						'raw'        => [],
				];

				$creator = User::where( 'id', $this->author_id )->first();
				if ( $creator !== null ) {
						$retArr['raw']['creator'] = [
								'id'     => $this->author_id,
								'name'   => $creator->getFullName(),
								'email'  => $creator->email,
								'avatar' => $creator->getPhotoUrl(),
								'phone'  => $creator->phone,
						];
				}

				return $retArr;
		}
}
