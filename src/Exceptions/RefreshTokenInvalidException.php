<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The presented refresh token is malformed, matches no row, or its secret does
 * not verify.
 *
 * The three cases deliberately share one code and one message: distinguishing
 * them would tell an attacker whether an identifier exists.
 */
final class RefreshTokenInvalidException extends SanctumRefreshTokenException
{
    public static function make(): self
    {
        return new self('The refresh token is invalid.');
    }

    public function errorCode(): string
    {
        return 'refresh_token_invalid';
    }
}
