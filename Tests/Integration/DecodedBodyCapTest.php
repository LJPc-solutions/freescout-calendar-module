<?php

namespace Modules\LJPcCalendarModule\Tests\Integration;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Utils;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavAnswerTooLarge;
use Modules\LJPcCalendarModule\CalendarProviders\CalDav\CalDavClient;
use Modules\LJPcCalendarModule\CalendarProviders\Exceptions\ProviderUnavailable;
use Modules\LJPcCalendarModule\CalendarProviders\HttpClientFactory;
use Modules\LJPcCalendarModule\CalendarProviders\Ics\IcsProvider;
use Modules\LJPcCalendarModule\CalendarProviders\SyncBudget;
use Modules\LJPcCalendarModule\Entities\Calendar;
use Modules\LJPcCalendarModule\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Against a real HTTP server (PHP's built-in one, started here) that sends a gzip body whatever the request asks: curl
 * inflates it before FreeScout sees it, so the size limits must count the inflated bytes while they arrive, not the
 * bytes on the wire (a Content-Length or progress check) or the body once it is complete.
 */
class DecodedBodyCapTest extends TestCase
{
    private const MB = 1024 * 1024;

    /** @var resource|null */
    private $server = null;

    private string $dir;

    private string $base;

    /** Every response the real handler completed, with the size of its decoded body. */
    private HttpClientFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ljpc-gzip-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        // Zeros inflate about a thousand times: each file is a few dozen KB on the wire and 4 MB over its limit inflated.
        foreach (['ics' => IcsProvider::MAX_BYTES, 'dav' => CalDavClient::MAX_BYTES] as $name => $limit) {
            $deflate = deflate_init(ZLIB_ENCODING_GZIP);
            $file = fopen($this->dir . '/' . $name . '.gz', 'wb');
            $zeros = str_repeat("\0", self::MB);
            for ($written = 0; $written <= $limit + 3 * self::MB; $written += self::MB) {
                fwrite($file, deflate_add($deflate, $zeros, ZLIB_NO_FLUSH));
            }
            fwrite($file, deflate_add($deflate, '', ZLIB_FINISH));
            fclose($file);
        }
        file_put_contents($this->dir . '/router.php', '<?php
$file = __DIR__ . (strpos($_SERVER["REQUEST_URI"], "/feed") === 0 ? "/ics.gz" : "/dav.gz");
// Under /digest/ only a digest login gets the body; anything else gets the challenge.
if (strpos($_SERVER["REQUEST_URI"], "/digest/") === 0 && strpos($_SERVER["HTTP_AUTHORIZATION"] ?? "", "Digest ") !== 0) {
    http_response_code(401);
    header("WWW-Authenticate: Digest realm=\"dav\", nonce=\"abc\", qop=\"auth\"");
    exit;
}
header("Content-Type: text/calendar");
header("Content-Encoding: gzip");
header("Content-Length: " . filesize($file));
readfile($file);
');
        $port = random_int(20000, 39999);
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->dir . '/router.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $deadline = microtime(true) + 5;
        while (!@fsockopen('127.0.0.1', $port, $errno, $error, 0.2)) {
            if (microtime(true) > $deadline) {
                $this->markTestSkipped('PHP\'s built-in server did not start on port ' . $port . '.');
            }
            usleep(50000);
        }
        $this->base = 'http://127.0.0.1:' . $port;
        config(['app.remote_host_white_list' => '127.0.0.1']);
        // Measured at curl itself, below every middleware: Guzzle's digest login writes each leg into a sink of its own.
        $this->http = new class () extends HttpClientFactory {
            /** @var int[] */
            public array $decoded = [];

            protected function handlerStack(): HandlerStack
            {
                $curl = Utils::chooseHandler();

                return HandlerStack::create(function ($request, array $options) use ($curl) {
                    return $curl($request, $options)->then(function (ResponseInterface $response) {
                        $this->decoded[] = (int) $response->getBody()->getSize();

                        return $response;
                    });
                });
            }
        };
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_an_ics_feed_that_inflates_past_20_mb_is_cut_off_while_it_arrives(): void
    {
        $calendar = new Calendar(['name' => 'Feed', 'provider' => Calendar::PROVIDER_ICS, 'remote_id' => $this->base . '/feed.ics']);

        try {
            (new IcsProvider($this->http))->pull($calendar, [], SyncBudget::seconds(30));
            $this->fail('The feed was read.');
        } catch (ProviderUnavailable $e) {
            $this->assertSame('The calendar feed is too large (over 20 MB).', $e->userMessage());
        }
        foreach ($this->http->decoded as $size) {
            $this->assertLessThanOrEqual(IcsProvider::MAX_BYTES, $size, 'the inflated feed was received in full');
        }
    }

    public function test_a_caldav_answer_that_inflates_past_50_mb_is_cut_off_while_it_arrives(): void
    {
        // With a basic login, and with a digest login, which Guzzle runs through sinks of its own.
        foreach (['/dav/event.ics', '/digest/event.ics'] as $path) {
            try {
                (new CalDavClient($this->http, 'calendar-user', 'secret'))->get($this->base . $path);
                $this->fail('The answer was read: ' . $path);
            } catch (CalDavAnswerTooLarge $e) {
                $this->assertSame('The CalDAV server sent an answer over 50 MB.', $e->userMessage());
            }
            foreach ($this->http->decoded as $size) {
                $this->assertLessThanOrEqual(CalDavClient::MAX_BYTES, $size, 'the inflated answer was received in full: ' . $path);
            }
        }
    }
}
