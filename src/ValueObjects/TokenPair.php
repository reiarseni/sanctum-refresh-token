<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * The only moment either plaintext token exists outside the client. Neither is
 * recoverable afterwards: storage holds only hashes, so a pair the caller drops
 * on the floor is gone.
 *
 * @implements Arrayable<string, mixed>
 */
final class TokenPair implements Arrayable
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly ?Carbon $accessTokenExpiresAt,
        public readonly ?Carbon $refreshTokenExpiresAt,
        public readonly string $familyUuid,
        public readonly int $generation,
        public readonly ?Carbon $familyExpiresAt = null,
    ) {}

    /**
     * The shape a token endpoint can return as-is.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     access_token_expires_at: string|null,
     *     refresh_token_expires_at: string|null,
     *     family: string,
     *     generation: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'token_type' => 'Bearer',
            'access_token_expires_at' => $this->accessTokenExpiresAt?->toIso8601String(),
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt?->toIso8601String(),
            'family' => $this->familyUuid,
            'generation' => $this->generation,
        ];
    }
}
