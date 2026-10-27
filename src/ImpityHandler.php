<?php

declare(strict_types=1);

namespace Dekor\Impity;

use Dekor\Impity\Exception\CurlException;
use Dekor\Impity\Transport\TerminalCurlImpersonateTransport;
use Dekor\Impity\Transport\TransportInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Guzzle handler sending requests through curl-impersonate, use it in place of CurlHandler:
 * new GuzzleHttp\Client(['handler' => HandlerStack::create(new ImpityHandler(ImpityBrowser::Chrome152))]).
 *
 * Guzzle's middleware (redirects, cookies, http_errors) keeps working. Request options handled here:
 * browser (per request ImpityBrowser), proxy, timeout, connect_timeout, verify, decode_content, sink,
 * on_headers, on_stats. The protocol version is chosen by the browser profile (HTTP/2 over TLS),
 * Guzzle's "version" option is ignored. Requests run one at a time, see README for concurrency.
 */
final class ImpityHandler
{
    /** Request option to impersonate another browser for a single request. */
    public const OPTION_BROWSER = 'browser';

    // the same codes Guzzle's CurlHandler reports as ConnectException
    private const CONNECT_ERRORS = [5, 6, 7, 28, 35, 52];

    private TransportInterface $transport;

    public function __construct(
        private readonly ImpityBrowser $browser = ImpityBrowser::DEFAULT,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new TerminalCurlImpersonateTransport();
    }

    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        try {
            $browser = $options[self::OPTION_BROWSER] ?? $this->browser;
            $response = $this->transport->perform(
                $request,
                $browser instanceof ImpityBrowser ? $browser : ImpityBrowser::from($browser),
                $options,
            );
        } catch (CurlException $e) {
            $message = sprintf('cURL error %d: %s (see https://curl.se/libcurl/c/libcurl-errors.html) for %s', $e->getCode(), $e->getMessage(), $request->getUri());
            $exception = in_array($e->getCode(), self::CONNECT_ERRORS, true)
                ? new ConnectException($message, $request, $e, ['errno' => $e->getCode()])
                : new RequestException($message, $request, null, $e, ['errno' => $e->getCode()]);
            self::stats($options, $request, null, $exception);

            return Create::rejectionFor($exception);
        }

        if (isset($options['sink'])) {
            $response = $response->withBody(self::sink($options['sink'], (string) $response->getBody()));
        }

        if (isset($options['on_headers'])) {
            try {
                $options['on_headers']($response);
            } catch (\Throwable $e) {
                $exception = new RequestException('An error was encountered during the on_headers event', $request, $response, $e);
                self::stats($options, $request, $response, $exception);

                return Create::rejectionFor($exception);
            }
        }

        self::stats($options, $request, $response, null);

        return Create::promiseFor($response);
    }

    /**
     * @param mixed $sink Guzzle's "sink" option: file path, resource or StreamInterface
     */
    private static function sink(mixed $sink, string $body): StreamInterface
    {
        $stream = is_string($sink) ? new LazyOpenStream($sink, 'w+') : Utils::streamFor($sink);
        $stream->write($body);
        $stream->rewind();

        return $stream;
    }

    private static function stats(array $options, RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): void
    {
        if (isset($options['on_stats'])) {
            $time = $response instanceof ImpityResponse ? $response->getTotalTime() : 0.0;
            $options['on_stats'](new TransferStats($request, $response, $time, $error));
        }
    }
}
