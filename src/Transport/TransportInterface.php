<?php

declare(strict_types=1);

namespace Dekor\Impity\Transport;

use Dekor\Impity\Exception\CurlException;
use Dekor\Impity\ImpityBrowser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one request with a browser's fingerprint. Implementations check in their constructor
 * that they can run here and throw TransportUnavailableException otherwise.
 */
interface TransportInterface
{
    /**
     * @param array<string, mixed> $options Guzzle request options (client options merged with per-request ones),
     *                                      a transport reads the ones it supports and ignores the rest
     * @return ResponseInterface an ImpityResponse (built with ImpityResponse::fromCurl()) also carries the transfer time
     * @throws CurlException on a failed transfer, the code is the CURLcode
     */
    public function perform(RequestInterface $request, ImpityBrowser $browser, array $options): ResponseInterface;
}
