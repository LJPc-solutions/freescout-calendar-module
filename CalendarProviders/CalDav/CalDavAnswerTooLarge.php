<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\CalDav;

use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;

/** A CalDAV answer passed CalDavClient::MAX_BYTES; the provider splits a multiget that did. */
class CalDavAnswerTooLarge extends ProviderUnavailable
{
    public function __construct()
    {
        parent::__construct(__('The CalDAV server sent an answer over 50 MB.'));
    }
}
