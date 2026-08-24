<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * One live token family, seen from the outside.
 *
 * This is the package's public read model. Consumers get sessions, never
 * Eloquent rows, so that a column can be renamed without breaking anyone, and
 * so that `isCurrent` — which depends on which access token authenticated the
 * request, not on any column — is expressible at all.
 *
 * Readonly properties make mutation a fatal error rather than a silent no-op:
 * renaming goes through SessionManager::rename(), which validates the label and
 * writes it.
 *
 * @implements Arrayable<string, mixed>
 */
final class Session implements Arrayable
{
    public function __construct(
        public readonly string $familyUuid,
        public readonly string $label,
        public readonly Device $device,
        public readonly bool $isCurrent,
        public readonly int $generation,
        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $lastUsedAt,
        public readonly ?Carbon $expiresAt,
        public readonly ?Carbon $familyExpiresAt = null,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     device: array<string, mixed>,
     *     is_current: bool,
     *     generation: int,
     *     created_at: string|null,
     *     last_used_at: string|null,
     *     expires_at: string|null,
     *     family_expires_at: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->familyUuid,
            'label' => $this->label,
            'device' => $this->device->toArray(),
            'is_current' => $this->isCurrent,
            'generation' => $this->generation,
            'created_at' => $this->createdAt?->toIso8601String(),
            'last_used_at' => $this->lastUsedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'family_expires_at' => $this->familyExpiresAt?->toIso8601String(),
        ];
    }
}
