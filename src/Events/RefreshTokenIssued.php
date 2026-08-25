<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * Events carry identifiers, never plaintext token material: a listener that
 * logs the whole event must not thereby write a usable credential to disk.
 */
final class RefreshTokenIssued
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly int $generation = 1,
    ) {}
}
