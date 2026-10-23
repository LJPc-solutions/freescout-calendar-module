<?php

namespace Modules\LJPcCalendarModule\CalendarProviders;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Where curl writes one response body (Guzzle's "sink" option) for a size limit (HttpClientFactory::client()): a
 * temporary stream that refuses to grow past the limit. curl writes the body here after decoding it (gzip and the
 * like), so this counts the bytes FreeScout would hold, which a compressed body multiplies; a Content-Length or progress
 * check only sees the bytes on the wire. A refused write is short, and curl aborts the transfer on a short write.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $limit;

    private int $received = 0;

    public function __construct(int $limit)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'w+'));
        $this->limit = $limit;
    }

    public function write(string $string): int
    {
        $this->received += strlen($string);
        if ($this->received > $this->limit) {
            return 0;
        }

        return $this->stream->write($string);
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** Whether the body passed the limit; what arrived after that was dropped. */
    public function exceeded(): bool
    {
        return $this->received > $this->limit;
    }
}
