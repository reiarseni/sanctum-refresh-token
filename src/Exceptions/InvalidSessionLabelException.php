<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * A session label was too long or carried control characters.
 */
final class InvalidSessionLabelException extends SanctumRefreshTokenException
{
    public static function tooLong(int $length, int $maximum): self
    {
        return new self(sprintf(
            'The session label is %d characters long; the maximum is %d.',
            $length,
            $maximum,
        ));
    }

    public static function controlCharacters(): self
    {
        return new self('The session label contains control characters.');
    }

    public function errorCode(): string
    {
        return 'invalid_session_label';
    }
}
