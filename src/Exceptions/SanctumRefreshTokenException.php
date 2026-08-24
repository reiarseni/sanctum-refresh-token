<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

use RuntimeException;

/**
 * Base class for every failure the package raises.
 *
 * Every subclass carries a stable, machine-readable code so that a client can
 * branch on the outcome without parsing an English message. No subclass ever
 * carries plaintext token material, in its message or anywhere else.
 */
abstract class SanctumRefreshTokenException extends RuntimeException
{
    /**
     * The stable machine-readable identifier for this failure.
     */
    abstract public function errorCode(): string;
}
