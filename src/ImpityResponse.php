<?php

declare(strict_types=1);

namespace Dekor\Impity;

use GuzzleHttp\Psr7\Response;

/**
 * PSR-7 response returned by transports, with the transfer time curl measured.
 * It survives Guzzle's middleware: with*() methods clone it, keeping the class and the time.
 */
class ImpityResponse extends Response
{
    private float $totalTime = 0.0;

    /**
     * Builds the response from curl's header output, which holds a block per response:
     * proxy CONNECT, 1xx interim ones and the final one, the last block wins.
     *
     * @param bool $decoded the body was decompressed by curl, Content-Encoding is moved aside like Guzzle does
     * @param float $totalTime seconds the transfer took
     */
    public static function fromCurl(string $rawHeaders, string $body, bool $decoded, float $totalTime): self
    {
        $blocks = array_values(array_filter(
            preg_split("/\r?\n\r?\n/", trim($rawHeaders)),
            fn (string $block) => str_starts_with($block, 'HTTP/'),
        ));
        $lines = preg_split("/\r?\n/", end($blocks) ?: 'HTTP/1.1 200');

        preg_match('#^HTTP/([\d.]+)\s+(\d{3})(?:\s+(.*))?$#', trim(array_shift($lines)), $status);

        $headers = [];
        foreach ($lines as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        $names = array_combine(array_map('strtolower', array_keys($headers)), array_keys($headers));
        if ($decoded && $body !== '' && isset($names['content-encoding'])) {
            $headers['x-encoded-content-encoding'] = $headers[$names['content-encoding']];
            unset($headers[$names['content-encoding']]);
            if (isset($names['content-length'])) {
                $headers['x-encoded-content-length'] = $headers[$names['content-length']];
                unset($headers[$names['content-length']]);
            }
            $headers['Content-Length'] = [(string) strlen($body)];
        }

        $response = new self((int) ($status[2] ?? 200), $headers, $body, $status[1] ?? '1.1', $status[3] ?? null);
        $response->totalTime = $totalTime;

        return $response;
    }

    /**
     * Seconds the transfer took, as measured by curl (DNS, connect, TLS, request and response).
     */
    public function getTotalTime(): float
    {
        return $this->totalTime;
    }
}
