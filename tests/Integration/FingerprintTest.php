<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests\Integration;

use Dekor\Impity\Client;
use Dekor\Impity\Exception\TransportUnavailableException;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\Transport\TerminalCurlImpersonateTransport;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Checks the fingerprint seen by a public TLS echo service, catches profiles going stale.
 * Needs internet: excluded by `composer test:offline`.
 */
#[Group('network')]
#[Group('integration')]
final class FingerprintTest extends TestCase
{
    private static TerminalCurlImpersonateTransport $transport;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$transport = new TerminalCurlImpersonateTransport();
        } catch (TransportUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    public function testChromeFingerprint(): void
    {
        $seen = $this->fingerprint(ImpityBrowser::Chrome152);

        $this->assertSame('h2', $seen['http_version']);
        $this->assertStringStartsWith('t13d', $seen['tls']['ja4']);
        $this->assertStringContainsString('Chrome/', $seen['user_agent']);
    }

    public function testBrowsersHaveDifferentFingerprints(): void
    {
        $chrome = $this->fingerprint(ImpityBrowser::Chrome152);
        $firefox = $this->fingerprint(ImpityBrowser::Firefox155);

        $this->assertNotSame($chrome['tls']['ja4'], $firefox['tls']['ja4']);
        $this->assertNotSame($chrome['http2']['akamai_fingerprint_hash'], $firefox['http2']['akamai_fingerprint_hash']);
    }

    private function fingerprint(ImpityBrowser $browser): array
    {
        $response = (new Client($browser, transport: self::$transport))->sendRequest(new Request('GET', 'https://tls.peet.ws/api/all'));

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}
