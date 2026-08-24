<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A family advanced by one generation.
 */
final class RefreshTokenRotated
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly int $generation,
        public readonly int $previousGeneration,
    ) {}
}
