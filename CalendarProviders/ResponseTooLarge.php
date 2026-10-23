<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

/**
 * A response body passed the limit its client was built with (HttpClientFactory::client()); the transfer was aborted
 * there. Callers turn it into their own "too large" message.
 */
final class ResponseTooLarge extends \RuntimeException
{
}
