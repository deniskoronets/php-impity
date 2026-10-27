<?php

declare(strict_types=1);

namespace Dekor\Impity\Exception;

/**
 * A failed transfer; the code is the CURLcode (CURLE_*).
 * ImpityHandler turns it into Guzzle's ConnectException / RequestException.
 */
final class CurlException extends \RuntimeException
{
}
