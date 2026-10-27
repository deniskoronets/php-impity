<?php

declare(strict_types=1);

namespace Dekor\Impity\Exception;

/**
 * The transport can't run in this environment: proc_open() is disabled, or the curl-impersonate
 * binary is missing or isn't curl-impersonate.
 */
final class TransportUnavailableException extends \RuntimeException
{
}
