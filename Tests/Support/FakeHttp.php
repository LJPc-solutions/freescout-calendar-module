<?php

namespace Modules\LJPcCalendarModule\Tests\Support;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Replaces the network for every client the module builds: queued responses answer the requests in order, and
 * every request that reaches the network is recorded. Core's options and the host check stay real.
 */
class FakeHttp extends HttpClientFactory
{
    private MockHandler $mock;

    private array $history = [];

    public function __construct()
    {
        $this->mock = new MockHandler();
    }

    /**
     * Binds a new fake as the container's HttpClientFactory and returns it. Install it before anything resolves a
     * provider: the registry builds each provider once, with the factory bound at that moment.
     */
    public static function install(): self
    {
        $fake = new self();
        app()->instance(HttpClientFactory::class, $fake);

        return $fake;
    }

    /**
     * Each value answers one request: a Response, a Throwable to throw, or a callable that gets the request and
     * the request options and returns either.
     *
     * @param ResponseInterface|\Throwable|callable ...$responses
     */
    public function queue(...$responses): self
    {
        $this->mock->append(...$responses);

        return $this;
    }

    /** @return array[] one ['method', 'url', 'headers', 'body'] per request that reached the network */
    public function history(): array
    {
        return $this->history;
    }

    /** Responses queued but not used yet. */
    public function pending(): int
    {
        return $this->mock->count();
    }

    protected function handlerStack(): HandlerStack
    {
        return HandlerStack::create(function (RequestInterface $request, array $options) {
            $this->history[] = [
                'method'  => $request->getMethod(),
                'url'     => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'body'    => (string) $request->getBody(),
            ];

            return ($this->mock)($request, $options);
        });
    }
}
