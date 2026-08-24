<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * A rotation asked for abilities the family was never granted.
 *
 * Rotation may narrow abilities; it may never widen them, or a stolen refresh
 * token would be a privilege-escalation primitive.
 */
final class AbilitiesEscalationException extends SanctumRefreshTokenException
{
    /**
     * @param  list<string>  $requested
     * @param  list<string>  $granted
     */
    public static function make(array $requested, array $granted): self
    {
        return new self(sprintf(
            'Rotation requested abilities [%s] which are not a subset of the granted [%s].',
            implode(', ', $requested),
            implode(', ', $granted),
        ));
    }

    public function errorCode(): string
    {
        return 'abilities_escalation';
    }
}
