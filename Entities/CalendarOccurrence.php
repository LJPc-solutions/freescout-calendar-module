<?php

namespace Modules\LJPcCalendarModule\Entities;

use Illuminate\Database\Eloquent\Model;

class CalendarOccurrence extends Model
{
    public $timestamps = false;

    protected $table = 'calendar_occurrences';

    protected $guarded = ['id'];

    protected $casts = [
        'event_id'    => 'integer',
        'calendar_id' => 'integer',
        'all_day'     => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }
}
