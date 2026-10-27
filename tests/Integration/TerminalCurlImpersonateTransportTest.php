<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests\Integration;

use Dekor\Impity\Client;
use Dekor\Impity\Exception\TransportUnavailableException;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\ImpityProxy;
use Dekor\Impity\ImpityResponse;
use Dekor\Impity\Transport\TerminalCurlImpersonateTransport;
use Dekor\Impity\Transport\TransportInterface;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Real requests through curl-impersonate against a local `php -S` server,
 * skipped when the binary isn't available (set IMPITY_CURL_IMPERSONATE_PATH).
 */
#[Group('integration')]
final class TerminalCurlImpersonateTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $baseUri;

    private static TransportInterface $transport;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$transport = new TerminalCurlImpersonateTransport();
        } catch (TransportUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::$baseUri = 'http://' . $address;

        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/../Fixtures/server.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '4'],
        );

        for ($i = 0; $i < 100 && ! @fsockopen('127.0.0.1', (int) parse_url(self::$baseUri, PHP_URL_PORT)); $i++) {
            usleep(50_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
        }
    }

    public function testSendsBrowserHeadersInsteadOfGuzzleOnes(): void
    {
        $echo = $this->echo($this->client(ImpityBrowser::Chrome152)->sendRequest($this->request('GET', '/echo')));

        $this->assertSame('GET', $echo['method']);
        $this->assertStringContainsString('Chrome/', $echo['headers']['user-agent']);
        $this->assertStringNotContainsString('GuzzleHttp', $echo['headers']['user-agent']);
        $this->assertArrayHasKey('accept', $echo['headers']);
        $this->assertArrayNotHasKey('expect', $echo['headers']);
    }

    public function testCallerHeadersReplaceBrowserOnesExceptUserAgent(): void
    {
        $echo = $this->echo($this->client()->sendRequest($this->request('GET', '/echo', [
            'User-Agent' => 'MyCrawler/1.0',
            'Accept-Language' => 'uk-UA',
            'X-Custom' => 'yes',
        ])));

        $this->assertStringContainsString('Chrome/', $echo['headers']['user-agent']);
        $this->assertSame('uk-UA', $echo['headers']['accept-language']);
        $this->assertSame('yes', $echo['headers']['x-custom']);
    }

    public function testUsesTheClientsBrowser(): void
    {
        $echo = $this->echo($this->client(ImpityBrowser::Firefox155)->sendRequest($this->request('GET', '/echo')));

        $this->assertStringContainsString('Firefox/', $echo['headers']['user-agent']);
    }

    public function testPostsBody(): void
    {
        $echo = $this->echo($this->client()->sendRequest($this->request('POST', '/echo', ['Content-Type' => 'application/json'], '{"a":1}')));

        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"a":1}', $echo['body']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
    }

    public function testCustomMethodWithoutBody(): void
    {
        $echo = $this->echo($this->client()->sendRequest($this->request('DELETE', '/echo')));

        $this->assertSame('DELETE', $echo['method']);
        $this->assertSame('', $echo['body']);
        $this->assertArrayNotHasKey('content-type', $echo['headers']);
    }

    public function testHead(): void
    {
        $response = $this->client()->sendRequest($this->request('HEAD', '/echo'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testReturnsRedirectsWithoutFollowing(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/redirect'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/echo', $response->getHeaderLine('Location'));
    }

    public function testReturnsErrorResponses(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/status/500'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('boom', (string) $response->getBody());
    }

    public function testDecodesCompressedBody(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/gzip'));

        $this->assertSame('compressed body', (string) $response->getBody());
        $this->assertSame('gzip', $response->getHeaderLine('X-Encoded-Content-Encoding'));
    }

    public function testTimeoutIsNetworkException(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        $this->clientWith(['timeout' => 0.5])->sendRequest($this->request('GET', '/slow'));
    }

    public function testRefusedConnectionIsNetworkException(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        $this->client()->sendRequest(new Request('GET', 'http://127.0.0.1:1/'));
    }

    public function testResponseCarriesTransferTime(): void
    {
        $response = $this->client()->sendRequest($this->request('GET', '/echo'));

        $this->assertInstanceOf(ImpityResponse::class, $response);
        $this->assertGreaterThan(0.0, $response->getTotalTime());
    }

    public function testUsesProxyFromOptions(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        // nothing listens there, so the request only fails if it really goes through the proxy
        $this->clientWith(['proxy' => new ImpityProxy('127.0.0.1', 1)])->sendRequest($this->request('GET', '/echo'));
    }

    public function testUsesProxyUrlFromOptions(): void
    {
        $this->expectException(NetworkExceptionInterface::class);

        $this->clientWith(['proxy' => 'http://127.0.0.1:1'])->sendRequest($this->request('GET', '/echo'));
    }

    public function testPerSchemeProxyOnlyAppliesToItsScheme(): void
    {
        $response = $this->clientWith(['proxy' => ['https' => 'http://127.0.0.1:1']])->sendRequest($this->request('GET', '/echo'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNoProxyHostsBypassTheProxy(): void
    {
        $response = $this->clientWith(['proxy' => ['http' => 'http://127.0.0.1:1', 'no' => ['127.0.0.1']]])
            ->sendRequest($this->request('GET', '/echo'));

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function clientWith(array $options): Client
    {
        return new Client(options: $options, transport: self::$transport);
    }

    private function client(ImpityBrowser $browser = ImpityBrowser::DEFAULT): Client
    {
        return new Client($browser, transport: self::$transport);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): Request
    {
        return new Request($method, self::$baseUri . $path, $headers, $body);
    }

    private function echo($response): array
    {
        $echo = json_decode((string) $response->getBody(), true);
        $echo['headers'] = array_change_key_case($echo['headers']);

        return $echo;
    }
}
