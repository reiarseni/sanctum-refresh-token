<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The model presented for issuance cannot hold Sanctum access tokens.
 */
final class InvalidTokenableException extends SanctumRefreshTokenException
{
    public static function missingHasApiTokens(string $class): self
    {
        return new self(sprintf(
            '[%s] cannot be issued a refresh token because it does not use Laravel\Sanctum\HasApiTokens.',
            $class,
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_tokenable';
    }
}
