<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A consumed refresh token was replayed outside the grace window.
 *
 * The forensic context is the point of this event: the generation that was
 * replayed against the generation the family had reached tells you how far
 * behind the replaying party was, and therefore roughly when the fork happened.
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
