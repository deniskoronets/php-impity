<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\ImpityProxy;
use PHPUnit\Framework\TestCase;

final class ImpityProxyTest extends TestCase
{
    public function testBuildsUrlWithEncodedCredentials(): void
    {
        $proxy = new ImpityProxy('1.2.3.4', 8000, 'user@mail', 'p:ss/w');

        $this->assertSame('http://user%40mail:p%3Ass%2Fw@1.2.3.4:8000', $proxy->toUrl());
    }

    public function testBuildsUrlWithoutCredentials(): void
    {
        $this->assertSame('socks5h://proxy.local:1080', (new ImpityProxy('proxy.local', 1080, scheme: 'socks5h'))->toUrl());
    }

    public function testParsesUrl(): void
    {
        $proxy = ImpityProxy::fromUrl('SOCKS5://user:p%3Ass@1.2.3.4:1080');

        $this->assertSame('1.2.3.4', $proxy->host);
        $this->assertSame(1080, $proxy->port);
        $this->assertSame('user', $proxy->username);
        $this->assertSame('p:ss', $proxy->password);
        $this->assertSame('socks5', $proxy->scheme);
        $this->assertSame('socks5://user:p%3Ass@1.2.3.4:1080', $proxy->toUrl());
    }

    public function testRejectsUrlWithoutPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImpityProxy::fromUrl('http://1.2.3.4');
    }

    public function testRejectsUnknownScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImpityProxy('1.2.3.4', 8000, scheme: 'ftp');
    }
}
