<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\ImpityBrowser;
use PHPUnit\Framework\TestCase;

final class ImpityBrowserTest extends TestCase
{
    public function testDefaultIsChrome(): void
    {
        $this->assertStringStartsWith('chrome', ImpityBrowser::DEFAULT->value);
    }

    public function testMobilePoolHasOnlyMobileBrowsers(): void
    {
        $this->assertNotEmpty(ImpityBrowser::mobile());
        foreach (ImpityBrowser::mobile() as $browser) {
            $this->assertMatchesRegularExpression('/_(ios|android)$/', $browser->value);
        }
        $this->assertNotContains(ImpityBrowser::OkHttp4Android, ImpityBrowser::mobile());
    }

    public function testDesktopPoolHasOnlyDesktopBrowsers(): void
    {
        $this->assertContains(ImpityBrowser::DEFAULT, ImpityBrowser::desktop());
        foreach (ImpityBrowser::desktop() as $browser) {
            $this->assertDoesNotMatchRegularExpression('/_(ios|android)$/', $browser->value);
        }
    }

    public function testPoolsCoverEveryBrowserButOkHttp(): void
    {
        $pooled = array_merge(ImpityBrowser::desktop(), ImpityBrowser::mobile());

        $this->assertSame(count(ImpityBrowser::cases()) - 1, count($pooled));
    }

    public function testTargetNamesAreLibcurlImpersonateOnes(): void
    {
        foreach (ImpityBrowser::cases() as $browser) {
            $this->assertMatchesRegularExpression('/^(chrome|edge|firefox|safari|tor|okhttp)[0-9a-z_]+$/', $browser->value);
        }
    }
}
