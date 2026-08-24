<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The refresh token is past its own expiry. Its family is left intact: an
 * expiry is not evidence of a replay.
 */
final class RefreshTokenExpiredException extends SanctumRefreshTokenException
{
    public static function make(): self
    {
        return new self('The refresh token has expired.');
    }

    public function errorCode(): string
    {
        return 'refresh_token_expired';
    }
}
