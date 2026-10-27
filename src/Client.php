<?php

declare(strict_types=1);

namespace Dekor\Impity;

use Dekor\Impity\Transport\TransportInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client impersonating a browser.
 *
 * Exceptions implement the PSR-18 ones (NetworkExceptionInterface, RequestExceptionInterface).
 * As PSR-18 requires, sendRequest() doesn't follow redirects and doesn't throw on 4xx/5xx.
 */
final class Client implements ClientInterface
{
    private const DEFAULT_OPTIONS = [
        'timeout' => 30.0,
        'connect_timeout' => 10.0,
    ];

    // sends the requests, with ImpityHandler instead of Guzzle's own curl handler
    private GuzzleClient $guzzle;

    /**
     * @param array<string, mixed> $options Guzzle-compatible options: proxy (ImpityProxy, URL or per-scheme array),
     *                                      timeout, connect_timeout, verify, decode_content, headers, cookies, base_uri
     * @param TransportInterface|null $transport defaults to TerminalCurlImpersonateTransport
     */
    public function __construct(
        ImpityBrowser $browser = ImpityBrowser::DEFAULT,
        array $options = [],
        ?TransportInterface $transport = null,
    ) {
        $this->guzzle = new GuzzleClient(
            ['handler' => HandlerStack::create(new ImpityHandler($browser, $transport))] + $options + self::DEFAULT_OPTIONS,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->guzzle->sendRequest($request);
    }
}
