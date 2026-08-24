<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A consumed refresh token was presented again inside the grace window.
 *
 * This is the benign refresh race: a mobile client whose first request
 * succeeded but whose response was lost, retrying moments later. The family
 * survives, but the replay is still worth watching — an anomalous rate of
 * these is the signal that separates a flaky network from a token that two
 * parties hold.
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
