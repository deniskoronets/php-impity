# php-impity

**Impit for PHP.** A PSR-18 HTTP client that looks like a real browser on the wire: Chrome, Firefox, Safari or Tor
TLS and HTTP/2 fingerprints, with the browser's own headers. Made for crawling HTML and getting through anti-bot
protection.

It is a PHP analog inspired by [impit](https://github.com/apify/impit), Apify's browser-impersonating HTTP client for Node.js
and Python. impit patches rustls and h2 in Rust; php-impity drives
[curl-impersonate](https://github.com/lexiforest/curl-impersonate), curl built on BoringSSL (Chrome's TLS library)
with the exact handshake of each browser.

## Contents

- [What it does](#what-it-does)
- [Installation](#installation)
- [Usage](#usage)
  - [Laravel](#laravel)
  - [Browsers](#browsers)
  - [Headers](#headers)
- [Concurrency](#concurrency)
- [Limitations](#limitations)
- [Tests](#tests)

## What it does

Anti-bot systems (Cloudflare, Akamai, DataDome...) score every request before any challenge is shown. Two passive signals
give regular PHP clients away even with a browser `User-Agent`:

- **TLS fingerprint (JA3/JA4)**: cipher suites, extensions and their order in the ClientHello. PHP's curl with OpenSSL
  looks nothing like Chrome.
- **HTTP/2 fingerprint**: SETTINGS frame values, window updates and pseudo-header order.

php-impity makes both, plus the request headers, match one real browser build, so it lets you:

- fetch pages that answer regular PHP clients with 403 or a challenge page, but serve a real browser fine
- crawl at plain HTTP cost, no headless browser needed for pages that are only fingerprint-gated
- reuse a `cf_clearance` cookie solved in a real browser: it only works with the same fingerprint, IP and `User-Agent`
- rotate browser profiles and route requests through proxies

What it does **not** do: execute JavaScript, solve Turnstile / "Just a moment..." / CAPTCHA challenges once they are shown,
or fix a bad IP reputation. Use a real browser or an unblocker API for the first two and proxies for the last.

## Installation

```bash
composer require dekor/php-impity
```

- PHP 8.1+ with `proc_open()` enabled
- the `curl-impersonate` binary v2.3.1 (the targets in `ImpityBrowser` match this version), glibc or musl build matching
  your system. It is not shipped with the package; it is self-contained and only needs the system libc.

Requests run the binary, one process per request. Every option goes through a config on its stdin, so proxy
credentials and cookies never appear in the process list.

The binary is looked up in this order: the `TerminalCurlImpersonateTransport` constructor argument, the
`IMPITY_CURL_IMPERSONATE_PATH` env variable, `curl-impersonate` in `PATH`. The transport checks in its constructor that
`proc_open()` works and the binary really is curl-impersonate (stock curl is rejected), and throws
`Dekor\Impity\Exception\TransportUnavailableException` with the reason otherwise.

### Docker (Debian based images, e.g. `php:8.3-fpm`)

```dockerfile
ARG CURL_IMPERSONATE_VERSION=2.3.1

RUN curl -fsSL "https://github.com/lexiforest/curl-impersonate/releases/download/v${CURL_IMPERSONATE_VERSION}/curl-impersonate-v${CURL_IMPERSONATE_VERSION}.$(uname -m)-linux-gnu.tar.gz" \
        | tar -xz -C /usr/local/bin curl-impersonate
```

### Docker (Alpine images)

```dockerfile
ARG CURL_IMPERSONATE_VERSION=2.3.1

RUN apk add --no-cache curl \
    && curl -fsSL "https://github.com/lexiforest/curl-impersonate/releases/download/v${CURL_IMPERSONATE_VERSION}/curl-impersonate-v${CURL_IMPERSONATE_VERSION}.$(uname -m)-linux-musl.tar.gz" \
        | tar -xz -C /usr/local/bin curl-impersonate
```

### Plain Linux / macOS

```bash
VERSION=2.3.1
# Linux: x86_64-linux-gnu, aarch64-linux-gnu, x86_64-linux-musl...; macOS: arm64-macos, x86_64-macos
PLATFORM=x86_64-linux-gnu

curl -fsSL "https://github.com/lexiforest/curl-impersonate/releases/download/v${VERSION}/curl-impersonate-v${VERSION}.${PLATFORM}.tar.gz" \
    | sudo tar -xz -C /usr/local/bin curl-impersonate
```

## Usage

```php
use Dekor\Impity\Client;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\ImpityProxy;
use GuzzleHttp\Psr7\HttpFactory;

$client = new Client(ImpityBrowser::Chrome152, [
    'proxy' => new ImpityProxy('1.2.3.4', 8000, 'user', 'pass'), // or a URL: 'socks5h://user:pass@1.2.3.4:1080'
    'timeout' => 30,
]);

$factory = new HttpFactory(); // any PSR-17 factory
$response = $client->sendRequest($factory->createRequest('GET', 'https://example.com'));

$html = (string) $response->getBody();
```

Options are guzzle-compatible:

| Option | Default | |
|---|---|---|
| `proxy` | none | `ImpityProxy`, a proxy URL, or `['http' => ..., 'https' => ..., 'no' => ['.internal']]` |
| `timeout` | `30` | total seconds per request, `0` for none |
| `connect_timeout` | `10` | seconds to connect |
| `verify` | `true` | `false` skips TLS verification, a string is a CA bundle path |
| `decode_content` | `true` | `false` returns the body as sent (gzip, br...) |
| `headers` | none | headers added to every request, see [Headers](#headers) |
| `cookies` | none | a `GuzzleHttp\Cookie\CookieJar`: cookies set by responses are sent on later requests |
| `base_uri` | none | base for relative request URIs |

`Client` is built on Guzzle, so failures throw Guzzle exceptions: `GuzzleHttp\Exception\ConnectException` for network
errors (DNS, connect, proxy, timeout) and `GuzzleHttp\Exception\RequestException` for the rest (e.g. TLS verification).

### Laravel

```php
use Dekor\Impity\ImpityHandler;

// Laravel's own features (Http::fake(), events, retry, redirects) stay on top of the impersonating transport
Http::setHandler(new ImpityHandler(ImpityBrowser::Chrome152))
    ->withOptions(['proxy' => $proxy->toUrl()])
    ->get($url);
```

### Browsers

All profiles are cases of the `Dekor\Impity\ImpityBrowser` enum, only recent versions are kept, `ImpityBrowser::DEFAULT` is the newest Chrome:

- desktop: Chrome 145-152 (151 and 152 also as macOS), Firefox 144-155, Safari 18.0-27.0, Tor 145
- mobile: Safari 18.0-27.0 on iOS
- `OkHttp4Android`: the HTTP client of Android apps, for mobile app APIs

```php
ImpityBrowser::desktop();  // list<ImpityBrowser> to rotate between
ImpityBrowser::mobile();

$desktop = ImpityBrowser::desktop();
$client = new Client($desktop[array_rand($desktop)], ['proxy' => $proxy]);
```

curl-impersonate has no recent Chrome for Android (the newest is 131), so the mobile pool is iOS Safari only.

### Headers

Each request goes out with the browser profile's headers, in that browser's order.

> **The library overrides your headers.** `User-Agent` and the `sec-ch-ua*` client hints always come from the
> `ImpityBrowser` profile: if you pass them, they are silently dropped. A `User-Agent` that doesn't match the TLS
> fingerprint is exactly what gets a client flagged, so the only way to change it is to pick another `ImpityBrowser`.

Other headers you set (`Accept`, `Accept-Language`, `Cookie`, `Referer`, `Authorization`, custom ones...) are sent: one
the profile already has (`Accept`, `Accept-Language`) is replaced in its place, `Referer` goes where the browser puts it,
the rest are added at the end.

`Host` and `Content-Length` are always derived from the URL and the body, values you set are ignored. `Expect` is never
sent, and a request body without a `Content-Type` goes without one. Over HTTPS requests use HTTP/2, as the browser does.

## Concurrency

A `Client` sends one request at a time: `sendRequest()` blocks until curl-impersonate exits. To crawl many pages at once,
run several of them in parallel.

**Queue workers.** The simplest and most robust way: one URL per job, a client per job, and as many workers as you want
requests in flight (e.g. Laravel Horizon `processes`, Symfony Messenger consumers, supervisord).

```php
public function handle(): void
{
    $response = (new Client(ImpityBrowser::Chrome152, ['proxy' => $this->proxy]))
        ->sendRequest((new HttpFactory())->createRequest('GET', $this->url));
    // ...
}
```

**Forked processes** within one script, e.g. with [spatie/fork](https://github.com/spatie/fork). Return plain values from
the children, they are serialized back to the parent:

```php
use Spatie\Fork\Fork;

$pages = Fork::new()
    ->concurrent(10)
    ->run(...array_map(
        fn (string $url) => fn () => (string) (new Client())->sendRequest((new HttpFactory())->createRequest('GET', $url))->getBody(),
        $urls,
    ));
```

**Swoole / OpenSwoole coroutines.** With all hooks enabled, `proc_open()` and its pipes become coroutine-aware, so every
coroutine's curl-impersonate process runs in parallel inside one PHP process:

```php
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

Swoole\Coroutine\run(function () use ($urls) {
    $client = new Client(ImpityBrowser::Chrome152);
    $factory = new HttpFactory();

    foreach ($urls as $url) {
        go(function () use ($client, $factory, $url) {
            $response = $client->sendRequest($factory->createRequest('GET', $url));
            // ...
        });
    }
});
```

A single `Client` can be shared between coroutines, it keeps no per-request state.

## Limitations

- Every request starts a curl-impersonate process (~10-30 ms and a few MB) and opens a new connection with a full TLS
  handshake, connections aren't reused between requests.
- A profile only stays convincing while that browser version is common, update curl-impersonate and the `ImpityBrowser`
  case you use from time to time.

## Tests

```bash
composer test:offline   # without internet
composer test           # + fingerprint check against tls.peet.ws
```

Integration tests run when curl-impersonate is found (set `IMPITY_CURL_IMPERSONATE_PATH`) and are skipped otherwise.

## License

Apache-2.0

## Our sponsors

<a href="https://mobicard.com.ua/" title="Mobicard"><img src="https://mobicard.com.ua/favicon.svg" width="32" alt="Mobicard"></a>
<a href="https://busyb.com.ua/" title="BusyB"><img src="https://busyb.com.ua/favicon.svg" width="32" alt="BusyB"></a>
<a href="https://pc-info.com.ua/" title="PC-Info"><img src="https://pc-info.com.ua/favicon.svg" width="32" alt="PC-Info"></a>
<a href="https://linktrust.pro/" title="LinkTrust"><img src="https://linktrust.pro/linktrust.svg" width="32" alt="LinkTrust"></a>

## Author
Created and maintained by **[Denys Koronets](https://deniskoronets.com)** (**[Github](https://github.com/deniskoronets/)**)
