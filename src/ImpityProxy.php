<?php

declare(strict_types=1);

namespace Dekor\Impity;

/**
 * Proxy the client sends its requests through. Credentials are URL-encoded when the proxy URL is built,
 * so they can contain any characters.
 */
final class ImpityProxy
{
    private const SCHEMES = ['http', 'https', 'socks4', 'socks4a', 'socks5', 'socks5h'];

    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly string $scheme = 'http',
    ) {
        if (! in_array($scheme, self::SCHEMES, true)) {
            throw new \InvalidArgumentException('Unsupported proxy scheme "' . $scheme . '", expected one of: ' . implode(', ', self::SCHEMES));
        }
    }

    /**
     * @param string $url e.g. http://user:pass@1.2.3.4:8000 or socks5h://1.2.3.4:1080
     */
    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);
        if (empty($parts['host']) || empty($parts['port'])) {
            throw new \InvalidArgumentException('Proxy URL needs a host and a port: ' . $url);
        }

        return new self(
            $parts['host'],
            $parts['port'],
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            strtolower($parts['scheme'] ?? 'http'),
        );
    }

    public function toUrl(): string
    {
        $credentials = '';
        if ($this->username !== null) {
            $credentials = rawurlencode($this->username)
                . ($this->password !== null ? ':' . rawurlencode($this->password) : '')
                . '@';
        }

        return $this->scheme . '://' . $credentials . $this->host . ':' . $this->port;
    }
}
