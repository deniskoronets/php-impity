<?php

declare(strict_types=1);

namespace Dekor\Impity\Transport;

use Dekor\Impity\Exception\CurlException;
use Dekor\Impity\Exception\TransportUnavailableException;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\ImpityProxy;
use Dekor\Impity\ImpityResponse;
use Psr\Http\Message\RequestInterface;

/**
 * Runs the curl-impersonate binary for every request. Options go through a config read from stdin
 * (`--config -`), so proxy credentials and cookies never show up in the process list.
 *
 * The binary path comes from the constructor, then the IMPITY_CURL_IMPERSONATE_PATH env variable,
 * then a "curl-impersonate" lookup in PATH.
 */
final class TerminalCurlImpersonateTransport implements TransportInterface
{
    public const ENV_PATH = 'IMPITY_CURL_IMPERSONATE_PATH';

    private const BINARY_NAME = 'curl-impersonate';

    // printed by --write-out after the transfer, to get curl's own timing
    private const TIME_MARKER = 'impity-time:';

    /** @var array<string, true> binaries already checked, the check spawns a process */
    private static array $verified = [];

    private string $binary;

    public function __construct(?string $binary = null)
    {
        if (! function_exists('proc_open')) {
            throw new TransportUnavailableException('proc_open() is disabled (disable_functions), the terminal transport needs it.');
        }

        $this->binary = self::resolve($binary ?? (getenv(self::ENV_PATH) ?: null));
        self::verify($this->binary);
    }

    public function perform(RequestInterface $request, ImpityBrowser $browser, array $options): ImpityResponse
    {
        $method = strtoupper($request->getMethod());
        $body = (string) $request->getBody();
        $decode = ($options['decode_content'] ?? true) !== false;
        $headersFile = tempnam(sys_get_temp_dir(), 'impity');
        $bodyFile = null;

        $config = [
            self::option('impersonate', $browser->value),
            self::option('url', (string) $request->getUri()->withFragment('')),
            self::option('silent'),
            self::option('show-error'),
            self::option('dump-header', $headersFile),
            self::option('write-out', '%{stderr}' . "\n" . self::TIME_MARKER . '%{time_total}'),
        ];

        if ($decode) {
            $config[] = self::option('compressed');
        }

        if ($method === 'HEAD') {
            $config[] = self::option('head');
        } elseif ($method !== 'GET' || $body !== '') {
            $bodyFile = tempnam(sys_get_temp_dir(), 'impity');
            file_put_contents($bodyFile, $body);
            $config[] = self::option('data-binary', '@' . $bodyFile);
            $config[] = self::option('request', $method);
        }

        if (($options['timeout'] ?? 0) > 0) {
            $config[] = self::option('max-time', sprintf('%.3F', $options['timeout']));
        }
        if (($options['connect_timeout'] ?? 0) > 0) {
            $config[] = self::option('connect-timeout', sprintf('%.3F', $options['connect_timeout']));
        }

        $proxy = $options['proxy'] ?? null;
        $proxyUrl = match (true) {
            $proxy instanceof ImpityProxy => $proxy->toUrl(),
            is_array($proxy) => $proxy[$request->getUri()->getScheme()] ?? null,
            default => $proxy ?: null,
        };
        if ($proxyUrl !== null) {
            $config[] = self::option('proxy', $proxyUrl);
        }
        if (is_array($proxy) && isset($proxy['no'])) {
            $config[] = self::option('noproxy', implode(',', (array) $proxy['no']));
        }

        $verify = $options['verify'] ?? true;
        if ($verify === false) {
            $config[] = self::option('insecure');
        } elseif (is_string($verify)) {
            $config[] = self::option('cacert', $verify);
        }

        foreach (self::headerLines($request) as $line) {
            $config[] = self::option('header', $line);
        }

        try {
            $process = proc_open(
                [$this->binary, '--config', '-'],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes,
            );
            if (! is_resource($process)) {
                throw new CurlException('Could not start ' . $this->binary, 2);
            }

            fwrite($pipes[0], implode("\n", $config) . "\n");
            fclose($pipes[0]);
            $responseBody = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $responseHeaders = (string) file_get_contents($headersFile);
        } finally {
            @unlink($headersFile);
            if ($bodyFile !== null) {
                @unlink($bodyFile);
            }
        }

        // curl's exit code is the CURLcode, the message is its "curl: (N) ..." line
        if ($exitCode !== 0) {
            preg_match('/curl: \(\d+\) (.+)/', $stderr, $error);

            // option errors (e.g. a missing --cacert file) have no "(N)", their first line says it all
            $message = $error[1] ?? strtok(trim(explode(self::TIME_MARKER, $stderr)[0]), "\n");

            throw new CurlException($message ?: 'curl-impersonate exited with code ' . $exitCode, $exitCode);
        }

        preg_match('/' . self::TIME_MARKER . '([\d.]+)/', $stderr, $time);

        return ImpityResponse::fromCurl(
            $responseHeaders,
            // --head prints the headers where the body would go
            $method === 'HEAD' ? '' : $responseBody,
            $decode,
            (float) ($time[1] ?? 0),
        );
    }

    /**
     * Request headers as curl "Name: value" lines without the ones Guzzle and PSR-7 add by themselves.
     * The browser identity (User-Agent and the sec-ch-ua client hints) always comes from the
     * ImpityBrowser profile, callers can't override it. Other headers replace the profile's ones.
     *
     * @return list<string>
     */
    private static function headerLines(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);

            // Host and Content-Length are derived by curl from the URL and body
            if ($lower === 'host' || $lower === 'content-length' || $lower === 'expect') {
                continue;
            }

            // a User-Agent not matching the TLS fingerprint is exactly what gets clients flagged
            if ($lower === 'user-agent' || str_starts_with($lower, 'sec-ch-ua')) {
                continue;
            }

            $lines[] = $name . ': ' . implode(', ', $values);
        }

        // browsers never send "Expect: 100-continue" and no urlencoded Content-Type curl adds to bodies
        $lines[] = 'Expect:';
        if (! $request->hasHeader('Content-Type')) {
            $lines[] = 'Content-Type:';
        }

        return $lines;
    }

    /**
     * A line of a curl config file: `name` or `name = "value"`.
     */
    private static function option(string $name, ?string $value = null): string
    {
        return $value === null ? $name : $name . ' = "' . addcslashes($value, "\\\"\n\r\t") . '"';
    }

    private static function resolve(?string $binary): string
    {
        if ($binary !== null) {
            if (! is_file($binary) || ! is_executable($binary)) {
                throw new TransportUnavailableException($binary . ' is not an executable file.');
            }

            return $binary;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, '/') . '/' . self::BINARY_NAME;
            if ($dir !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new TransportUnavailableException(
            'curl-impersonate was not found in PATH, install it or point ' . self::ENV_PATH . ' to the binary, see README.'
        );
    }

    /**
     * Stock curl rejects --impersonate, curl-impersonate prints its version and exits with 0.
     */
    private static function verify(string $binary): void
    {
        if (isset(self::$verified[$binary])) {
            return;
        }

        $process = proc_open(
            [$binary, '--impersonate', ImpityBrowser::DEFAULT->value, '--version'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            throw new TransportUnavailableException('Could not start ' . $binary . '.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || ! str_starts_with($output, 'curl ')) {
            throw new TransportUnavailableException($binary . ' is not curl-impersonate: ' . trim($output));
        }

        self::$verified[$binary] = true;
    }
}
