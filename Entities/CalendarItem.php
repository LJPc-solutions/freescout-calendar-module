<?php

namespace Modules\LJPcCalendarModule\Entities;

use App\Conversation;
use App\User;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JsonSerializable;

/**
 * @property int $id
 * @property string $uid
 * @property int $calendar_id
 * @property int $author_id
 * @property bool $is_all_day
 * @property bool $is_private
 * @property bool $is_read_only
 * @property string $title
 * @property string $body
 * @property string $state
 * @property string $location
 * @property DateTime $start
 * @property DateTime $end
 * @property array|null $custom_fields
 */
class CalendarItem extends Model implements JsonSerializable {
		public const ACTION_TYPE_ADD_TO_CALENDAR = 186;

		protected $table = 'calendar_items';
		protected $fillable = [
				'id',
				'uid',
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
				'custom_fields',
		];

		protected $casts = [
				'start'         => 'datetime',
				'end'           => 'datetime',
				'custom_fields' => 'array',
		];

		public function calendar(): BelongsTo {
				return $this->belongsTo( Calendar::class );
		}

		public function jsonSerialize(): array {
				$customFields = $this->custom_fields;
				if ( isset( $customFields['author_id'] ) ) {
						$user = User::where( 'id', $customFields['author_id'] )->first();
						if ( $user === null ) {
								$customFields['author_name'] = __( 'Unknown' );
						} else {
								$customFields['author_name'] = $user->getFullName();
						}
				}
				if ( isset( $customFields['conversation_id'] ) ) {
						$customFields['conversation_id'] = (int) $customFields['conversation_id'];
						//get conversation url
						$conversation = Conversation::where( 'id', $customFields['conversation_id'] )->first();
						if ( $conversation !== null ) {
								$customFields['conversation_url'] = route( 'conversations.view', [ 'id' => $customFields['conversation_id'] ] );
						}
				}
				$retArr = [
						'id'         => $this->uid ?? $this->id,
						'title'      => $this->title,
						'location'   => $this->location,
						'body'       => $this->body,
						'state'      => $this->state,
						'start'      => ( new DateTimeImmutable( $this->start, new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM ),
						'end'        => ( new DateTimeImmutable( $this->end, new DateTimeZone( 'UTC' ) ) )->format( DATE_ATOM ),
						'isAllDay'   => (int) $this->is_all_day,
						'category'   => $this->is_all_day ? 'allday' : 'time',
						'isPrivate'  => (int) $this->is_private,
						'isReadOnly' => (int) $this->is_read_only,
						'calendarId' => (int) $this->calendar_id,
						'raw'        => [ 'customFields' => $customFields ],
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
