<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The family passed its absolute lifetime cap. No number of rotations can
 * extend a family beyond it; the holder has to authenticate again.
 */
final class FamilyExpiredException extends SanctumRefreshTokenException
{
    public static function make(): self
    {
        return new self('The token family has reached its absolute expiry.');
    }

    public function errorCode(): string
    {
        return 'family_expired';
    }
}
