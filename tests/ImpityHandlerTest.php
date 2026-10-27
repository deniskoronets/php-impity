<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\Exception\CurlException;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\ImpityHandler;
use Dekor\Impity\ImpityResponse;
use Dekor\Impity\Transport\TransportInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ImpityHandlerTest extends TestCase
{
    public function testPassesBrowserAndReturnsTransportResponse(): void
    {
        $transport = $this->transport(fn () => ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", 'ok', true, 0.4));

        $response = $this->client($transport, ImpityBrowser::Safari27_0)->get('https://example.com');

        $this->assertSame('ok', (string) $response->getBody());
        $this->assertSame(ImpityBrowser::Safari27_0, $transport->calls[0]['browser']);
    }

    public function testBrowserOptionOverridesForOneRequest(): void
    {
        $transport = $this->transport(fn () => ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", '', true, 0.0));
        $client = $this->client($transport, ImpityBrowser::Chrome152);

        $client->get('https://example.com', [ImpityHandler::OPTION_BROWSER => ImpityBrowser::Firefox155]);
        $client->get('https://example.com', [ImpityHandler::OPTION_BROWSER => 'safari270']);

        $this->assertSame(ImpityBrowser::Firefox155, $transport->calls[0]['browser']);
        $this->assertSame(ImpityBrowser::Safari27_0, $transport->calls[1]['browser']);
    }

    public function testWritesBodyIntoSink(): void
    {
        $sink = fopen('php://temp', 'w+');
        $transport = $this->transport(fn () => ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", 'to sink', true, 0.0));

        $response = $this->client($transport)->get('https://example.com', ['sink' => $sink]);

        $this->assertSame('to sink', (string) $response->getBody());
        rewind($sink);
        $this->assertSame('to sink', stream_get_contents($sink));
    }

    public function testReportsCurlTimeToOnStats(): void
    {
        $transport = $this->transport(fn () => ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", '', true, 0.75));
        $stats = null;

        $this->client($transport)->get('https://example.com', ['on_stats' => function (TransferStats $s) use (&$stats) {
            $stats = $s;
        }]);

        $this->assertSame(0.75, $stats->getTransferTime());
    }

    public function testConnectErrorsBecomeConnectException(): void
    {
        $transport = $this->transport(fn () => throw new CurlException('Operation timed out', 28));

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL error 28: Operation timed out');

        $this->client($transport)->get('https://example.com');
    }

    public function testOtherErrorsBecomeRequestException(): void
    {
        $transport = $this->transport(fn () => throw new CurlException('Peer certificate cannot be authenticated', 60));

        try {
            $this->client($transport)->get('https://example.com');
            $this->fail('No exception thrown');
        } catch (RequestException $e) {
            $this->assertNotInstanceOf(ConnectException::class, $e);
            $this->assertSame(60, $e->getHandlerContext()['errno']);
        }
    }

    /**
     * @param \Closure(): ResponseInterface $perform
     */
    private function transport(\Closure $perform): TransportInterface
    {
        return new class ($perform) implements TransportInterface {
            /** @var list<array{request: RequestInterface, browser: ImpityBrowser, options: array}> */
            public array $calls = [];

            public function __construct(private readonly \Closure $perform)
            {
            }

            public function perform(RequestInterface $request, ImpityBrowser $browser, array $options): ResponseInterface
            {
                $this->calls[] = ['request' => $request, 'browser' => $browser, 'options' => $options];

                return ($this->perform)();
            }
        };
    }

    private function client(TransportInterface $transport, ImpityBrowser $browser = ImpityBrowser::DEFAULT): Client
    {
        return new Client(['handler' => HandlerStack::create(new ImpityHandler($browser, $transport))]);
    }
}
