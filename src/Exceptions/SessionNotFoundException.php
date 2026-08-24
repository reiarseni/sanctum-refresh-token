<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The named family does not exist, or belongs to a different tokenable.
 *
 * The two cases share one message so that the session endpoint cannot be used
 * to probe for other users' family identifiers.
 */
final class SessionNotFoundException extends SanctumRefreshTokenException
{
    public static function make(string $familyUuid): self
    {
        return new self(sprintf('No session [%s] belongs to this tokenable.', $familyUuid));
    }

    public function errorCode(): string
    {
        return 'session_not_found';
    }
}
