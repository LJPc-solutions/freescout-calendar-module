<?php

namespace Modules\LJPcCalendarModule\CalendarProviders\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A calendar provider refused or failed a request. The message is our own translated text, safe to show to agents
 * and to store in last_error; it never holds a provider's response body, a token or a URL.
 */
abstract class ProviderException extends \RuntimeException
{
    private ?int $httpStatus;

    /** $httpStatus is the provider's HTTP status when there was one; the sync engine adds it to last_error. */
    public function __construct(string $userMessage, ?int $httpStatus = null, ?\Throwable $previous = null)
    {
        parent::__construct($userMessage, 0, $previous);
        $this->httpStatus = $httpStatus;
    }

    /** The status FreeScout answers the agent with: 409 or 502. */
    abstract public function status(): int;

    /** changed_elsewhere, provider_unavailable or connection_needs_reauth. */
    abstract public function code(): string;

    public function userMessage(): string
    {
        return $this->getMessage();
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['code' => $this->code(), 'message' => $this->userMessage()], $this->status());
    }
}
