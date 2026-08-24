<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;

/**
 * Every live row of a family was revoked, for whatever reason.
 *
 * Dispatched once per family, whatever revoked it, so that an application can
 * react to "this session is over" in one listener rather than in five.
 */
final class TokenFamilyRevoked
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly RevocationReason $reason,
    ) {}
}
