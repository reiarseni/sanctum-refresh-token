<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * The benign refresh race: a client whose first request succeeded but whose
 * response was lost, retrying moments later. The family survives, but an
 * anomalous rate of these separates a flaky network from a token two parties
 * hold.
 */
final class RefreshTokenReplayedInGracePeriod
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly int $generation,
        public readonly float $secondsSinceRotation,
    ) {}
}
