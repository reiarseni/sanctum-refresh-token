<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * The replayed generation against the one the family had reached tells you how
 * far behind the replaying party was, and so roughly when the fork happened.
 */
final class RefreshTokenReuseDetected
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly int $replayedGeneration,
        public readonly int $currentGeneration,
        public readonly float $secondsSinceRotation,
    ) {}
}
