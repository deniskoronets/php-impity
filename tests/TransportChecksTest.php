<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\Exception\TransportUnavailableException;
use Dekor\Impity\Transport\TerminalCurlImpersonateTransport;
use PHPUnit\Framework\TestCase;

final class TransportChecksTest extends TestCase
{
    public function testTerminalTransportRejectsMissingBinary(): void
    {
        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('is not an executable file');

        new TerminalCurlImpersonateTransport('/nonexistent/curl-impersonate');
    }

    public function testTerminalTransportRejectsOtherBinaries(): void
    {
        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('is not curl-impersonate');

        new TerminalCurlImpersonateTransport('/bin/echo');
    }

    public function testTerminalTransportRejectsStockCurl(): void
    {
        if (! is_executable('/usr/bin/curl')) {
            $this->markTestSkipped('No stock curl at /usr/bin/curl.');
        }

        $this->expectException(TransportUnavailableException::class);
        $this->expectExceptionMessage('is not curl-impersonate');

        new TerminalCurlImpersonateTransport('/usr/bin/curl');
    }
}
