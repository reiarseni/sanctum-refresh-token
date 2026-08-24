<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A token family was opened at generation 1.
 *
 * Like every event in this package, it carries identifiers and never plaintext
 * token material: a listener that logs the whole event must not thereby write a
 * usable credential into a log file.
 */
final class RefreshTokenIssued
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly int $generation = 1,
    ) {}
}
