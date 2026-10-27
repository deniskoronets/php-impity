<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\ImpityResponse;
use PHPUnit\Framework\TestCase;

final class ImpityResponseTest extends TestCase
{
    public function testTakesFinalBlockAfterProxyConnectAndInterimResponses(): void
    {
        $raw = "HTTP/1.1 200 Connection established\r\n\r\n"
            . "HTTP/2 103\r\nlink: </style.css>; rel=preload\r\n\r\n"
            . "HTTP/2 404\r\ncontent-type: text/html\r\nset-cookie: a=1\r\nset-cookie: b=2\r\n\r\n";

        $response = ImpityResponse::fromCurl($raw, 'not found', true, 0.25);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getReasonPhrase());
        $this->assertSame('2', $response->getProtocolVersion());
        $this->assertSame(['a=1', 'b=2'], $response->getHeader('Set-Cookie'));
        $this->assertFalse($response->hasHeader('link'));
        $this->assertSame('not found', (string) $response->getBody());
        $this->assertSame(0.25, $response->getTotalTime());
    }

    public function testHttp11StatusLineWithReason(): void
    {
        $response = ImpityResponse::fromCurl("HTTP/1.1 200 Fine\r\nX-A: 1\r\n\r\n", 'ok', true, 0.0);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Fine', $response->getReasonPhrase());
        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    public function testDecodedBodyRewritesEncodingHeaders(): void
    {
        $raw = "HTTP/2 200\r\ncontent-encoding: br\r\ncontent-length: 12\r\n\r\n";

        $response = ImpityResponse::fromCurl($raw, 'decoded body', true, 0.0);

        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertSame('br', $response->getHeaderLine('X-Encoded-Content-Encoding'));
        $this->assertSame('12', $response->getHeaderLine('X-Encoded-Content-Length'));
        $this->assertSame('12', $response->getHeaderLine('Content-Length'));
    }

    public function testKeepsEncodingHeadersWhenNotDecoding(): void
    {
        $response = ImpityResponse::fromCurl("HTTP/2 200\r\ncontent-encoding: gzip\r\n\r\n", "\x1f\x8b...", false, 0.0);

        $this->assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    public function testTotalTimeSurvivesImmutableChanges(): void
    {
        $response = ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", 'ok', true, 1.5)->withHeader('X-A', '1')->withStatus(201);

        $this->assertInstanceOf(ImpityResponse::class, $response);
        $this->assertSame(1.5, $response->getTotalTime());
    }
}
