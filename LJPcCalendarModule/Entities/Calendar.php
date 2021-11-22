<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

class Calendar extends Model implements JsonSerializable {
	protected $table = 'calendars';
	protected $fillable = [
		'name',
		'url',
		'synchronization_token',
	];

	public function isVisible(): bool {
		$calendarList = explode( "\n", base64_decode( config( 'ljpccalendarmodule.calendar_list' ) ) );

		return in_array( $this->name, $calendarList, true ) || in_array( $this->url, $calendarList, true );
	}

	public function jsonSerialize(): array {
		return [
			'id'       => $this->id,
			'name'     => $this->name,
			'external' => $this->isExternal(),
			'colors'   => $this->getColors(),
		];
	}

	public function isExternal(): bool {
		return ! empty( $this->url );
	}

	public function getColors(): array {
		$colors         = $this->calendarColors();
		$calendarColors = $colors[ ($this->id-1) % count( $colors ) ];

		return [
			'backgroundColor' => $calendarColors[0],
			'borderColor'     => $calendarColors[1],
			'textColor'       => $calendarColors[2],
		];
	}

	private function calendarColors(): array {
		return [
			[ '#3498db', '#2980b9', '#ffffff' ],
			[ '#e74c3c', '#c0392b', '#ffffff' ],
			[ '#e67e22', '#d35400', '#ffffff' ],
			[ '#1abc9c', '#16a085', '#ffffff' ],
			[ '#9b59b6', '#8e44ad', '#ffffff' ],
			[ '#2ecc71', '#27ae60', '#ffffff' ],
			[ '#f1c40f', '#f39c12', '#000000' ],
			[ '#833471', '#6F1E51', '#ffffff' ],
			[ '#9980FA', '#5758BB', '#ffffff' ],
		];
	}
}
