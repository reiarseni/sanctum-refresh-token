<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Events;

use Illuminate\Database\Eloquent\Model;

/**
 * A rotation was refused because the family's recorded issuance context does
 * not match the one resolved for the current request.
 *
 * Far more often a misconfigured resolver or a reassigned user than an attack,
 * which is why the family survives by default — but it is always worth seeing.
 */
final class ContextMismatchDetected
{
    public function __construct(
        public readonly Model $tokenable,
        public readonly string $familyUuid,
        public readonly ?string $recordedContext,
        public readonly ?string $resolvedContext,
    ) {}
}
