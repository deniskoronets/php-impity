<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests\Integration;

use Dekor\Impity\Client;
use Dekor\Impity\Exception\TransportUnavailableException;
use Dekor\Impity\Transport\TerminalCurlImpersonateTransport;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

/**
 * The "verify" option against badssl.com test hosts. Needs internet: excluded by `composer test:offline`.
 */
#[Group('network')]
#[Group('integration')]
final class TlsVerificationTest extends TestCase
{
    private const SELF_SIGNED = 'https://self-signed.badssl.com/';

    private const VALID = 'https://badssl.com/';

    private static TerminalCurlImpersonateTransport $transport;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$transport = new TerminalCurlImpersonateTransport();
        } catch (TransportUnavailableException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    public function testRejectsSelfSignedCertificateByDefault(): void
    {
        try {
            $this->send([], self::SELF_SIGNED);
            $this->fail('No exception thrown');
        } catch (RequestExceptionInterface $e) {
            $this->assertNotInstanceOf(NetworkExceptionInterface::class, $e);
            $this->assertStringContainsString('cURL error 60', $e->getMessage());
        }
    }

    public function testVerifyFalseAcceptsSelfSignedCertificate(): void
    {
        $this->assertSame(200, $this->send(['verify' => false], self::SELF_SIGNED));
    }

    public function testVerifyWithCaBundlePath(): void
    {
        $bundle = '/etc/ssl/certs/ca-certificates.crt';
        if (! is_file($bundle)) {
            $this->markTestSkipped('No CA bundle at ' . $bundle);
        }

        $this->assertSame(200, $this->send(['verify' => $bundle], self::VALID));
    }

    public function testMissingCaBundleFails(): void
    {
        $this->expectException(RequestExceptionInterface::class);
        $this->expectExceptionMessage('/nonexistent/ca.pem');

        $this->send(['verify' => '/nonexistent/ca.pem'], self::VALID);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(array $options, string $url): int
    {
        return (new Client(options: $options, transport: self::$transport))->sendRequest(new Request('GET', $url))->getStatusCode();
    }
}
