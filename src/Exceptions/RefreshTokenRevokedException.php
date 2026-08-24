<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;

/**
 * The refresh token belongs to a family that has already been revoked.
 */
final class RefreshTokenRevokedException extends SanctumRefreshTokenException
{
    private function __construct(string $message, public readonly ?RevocationReason $reason)
    {
        parent::__construct($message);
    }

    public static function make(?RevocationReason $reason = null): self
    {
        return new self('The refresh token has been revoked.', $reason);
    }

    public function errorCode(): string
    {
        return 'refresh_token_revoked';
    }
}
