<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use InvalidArgumentException;
use Modules\LJPcCalendarModule\Entities\Calendar;

/** Maps calendars.provider to its provider. A singleton: each provider is built from the container once, on first use. */
class ProviderRegistry
{
    public const PROVIDERS = [
        Calendar::PROVIDER_LOCAL     => LocalProvider::class,
        Calendar::PROVIDER_ICS       => Ics\IcsProvider::class,
        Calendar::PROVIDER_CALDAV    => CalDav\CalDavProvider::class,
        Calendar::PROVIDER_GOOGLE    => Google\GoogleProvider::class,
        Calendar::PROVIDER_MICROSOFT => Microsoft\MicrosoftProvider::class,
    ];

    /** @var CalendarProvider[] */
    private array $instances = [];

    public function for(Calendar $calendar): CalendarProvider
    {
        return $this->forProvider((string) $calendar->provider);
    }

    public function forProvider(string $provider): CalendarProvider
    {
        if (!isset($this->instances[$provider])) {
            $this->instances[$provider] = app(self::classFor($provider));
        }

        return $this->instances[$provider];
    }

    /** Replaces one provider; tests use it to script a provider's answers. */
    public function swap(string $provider, CalendarProvider $instance): void
    {
        self::classFor($provider);
        $this->instances[$provider] = $instance;
    }

    private static function classFor(string $provider): string
    {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException('Unknown calendar provider "' . $provider . '".');
        }

        return self::PROVIDERS[$provider];
    }
}
