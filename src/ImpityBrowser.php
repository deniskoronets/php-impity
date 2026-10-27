<?php

declare(strict_types=1);

namespace Dekor\Impity;

/**
 * Browser profiles to impersonate. Each value is a libcurl-impersonate target name
 * (lexiforest/curl-impersonate v2.3.1), it sets the TLS ClientHello, HTTP/2 settings
 * and default headers of that exact browser build. Only recent versions are listed,
 * outdated ones stand out more than they blend in.
 */
enum ImpityBrowser: string
{
    /** Profile used when none is given. */
    public const DEFAULT = self::Chrome152;

    case Chrome145 = 'chrome145';
    case Chrome146 = 'chrome146';
    case Chrome150 = 'chrome150';
    case Chrome151 = 'chrome151';
    case Chrome151Macos = 'chrome151_macos';
    case Chrome152 = 'chrome152';
    case Chrome152Macos = 'chrome152_macos';

    case Firefox144 = 'firefox144';
    case Firefox147 = 'firefox147';
    case Firefox153 = 'firefox153';
    case Firefox154 = 'firefox154';
    case Firefox155 = 'firefox155';

    case Safari18_0 = 'safari18_0';
    case Safari18_0Ios = 'safari18_0_ios';
    case Safari18_4 = 'safari18_4';
    case Safari18_4Ios = 'safari18_4_ios';
    case Safari26_0 = 'safari26_0';
    case Safari26_0_1 = 'safari26_0_1';
    case Safari26_0Ios = 'safari26_0_ios';
    case Safari27_0 = 'safari270';
    case Safari27_0Ios = 'safari270_ios';

    case Tor145 = 'tor145';

    /** Android apps' HTTP client, not a browser: in neither pool. */
    case OkHttp4Android = 'okhttp4_android';

    /**
     * Desktop browsers (Windows/Linux, macOS), e.g. to rotate between.
     *
     * @return list<self>
     */
    public static function desktop(): array
    {
        return [
            self::Chrome145,
            self::Chrome146,
            self::Chrome150,
            self::Chrome151,
            self::Chrome151Macos,
            self::Chrome152,
            self::Chrome152Macos,
            self::Firefox144,
            self::Firefox147,
            self::Firefox153,
            self::Firefox154,
            self::Firefox155,
            self::Safari18_0,
            self::Safari18_4,
            self::Safari26_0,
            self::Safari26_0_1,
            self::Safari27_0,
            self::Tor145,
        ];
    }

    /**
     * Mobile browsers (iOS, Android).
     *
     * @return list<self>
     */
    public static function mobile(): array
    {
        return [
            self::Safari18_0Ios,
            self::Safari18_4Ios,
            self::Safari26_0Ios,
            self::Safari27_0Ios,
        ];
    }
}
