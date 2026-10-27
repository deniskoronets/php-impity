<?php

declare(strict_types=1);

namespace Dekor\Impity\Tests;

use Dekor\Impity\Client;
use Dekor\Impity\ImpityBrowser;
use Dekor\Impity\ImpityProxy;
use Dekor\Impity\ImpityResponse;
use Dekor\Impity\Transport\TransportInterface;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ClientTest extends TestCase
{
    public function testDefaults(): void
    {
        $call = $this->send(new Client(transport: $transport = $this->transport()), $transport);

        $this->assertSame(ImpityBrowser::DEFAULT, $call['browser']);
        $this->assertNull($call['options']['proxy'] ?? null);
        $this->assertSame(30.0, $call['options']['timeout']);
        $this->assertSame(10.0, $call['options']['connect_timeout']);
    }

    public function testOptionsReachTheTransport(): void
    {
        $proxy = new ImpityProxy('1.2.3.4', 8000, 'user', 'pass');
        $client = new Client(ImpityBrowser::Firefox155, [
            'proxy' => $proxy,
            'timeout' => 5,
            'verify' => false,
            'headers' => ['Accept-Language' => 'uk-UA'],
        ], $transport = $this->transport());

        $call = $this->send($client, $transport);

        $this->assertSame(ImpityBrowser::Firefox155, $call['browser']);
        $this->assertSame($proxy, $call['options']['proxy']);
        $this->assertSame(5, $call['options']['timeout']);
        $this->assertSame(10.0, $call['options']['connect_timeout']);
        $this->assertFalse($call['options']['verify']);
        $this->assertSame('uk-UA', $call['request']->getHeaderLine('Accept-Language'));
    }

    /**
     * @return array{request: RequestInterface, browser: ImpityBrowser, options: array}
     */
    private function send(Client $client, TransportInterface $transport): array
    {
        $client->sendRequest(new Request('GET', 'https://example.com'));

        return $transport->calls[0];
    }

    private function transport(): TransportInterface
    {
        return new class () implements TransportInterface {
            /** @var list<array{request: RequestInterface, browser: ImpityBrowser, options: array}> */
            public array $calls = [];

            public function perform(RequestInterface $request, ImpityBrowser $browser, array $options): ResponseInterface
            {
                $this->calls[] = ['request' => $request, 'browser' => $browser, 'options' => $options];

                return ImpityResponse::fromCurl("HTTP/2 200\r\n\r\n", '', true, 0.0);
            }
        };
    }
}
