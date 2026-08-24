<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Support\Identifier;

/**
 * One generation of one token family.
 *
 * This model is an implementation detail. Consumers read and manage families
 * through SessionManager and its Session value objects, so that the schema can
 * evolve without every column name becoming public API.
 *
 * @property int $id
 * @property string $family_uuid
 * @property string $tokenable_type
 * @property int|string $tokenable_id
 * @property int|null $access_token_id
 * @property string $name
 * @property string $token
 * @property list<string>|null $abilities
 * @property int|null $previous_id
 * @property int $generation
 * @property string|null $ip_hash
 * @property string|null $user_agent_hash
 * @property Carbon|null $expires_at
 * @property Carbon|null $family_expires_at
 * @property Carbon|null $rotated_at
 * @property Carbon|null $revoked_at
 * @property RevocationReason|null $revocation_reason
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RefreshToken extends Model
{
    /**
     * The token hash never leaves the package, not even by accident through a
     * model that a consumer serialised into a response.
     *
     * @var list<string>
     */
    protected $hidden = ['token', 'ip_hash', 'user_agent_hash'];

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'generation' => 'integer',
            'expires_at' => 'datetime',
            'family_expires_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revocation_reason' => RevocationReason::class,
        ];
    }

    /**
     * Resolved from configuration so that a consumer can rename the table
     * without forking the package. The name is validated as an identifier.
     */
    public function getTable(): string
    {
        $table = config('sanctum-refresh-token.table', 'refresh_tokens');

        return Identifier::assertSafe(
            is_string($table) ? $table : 'refresh_tokens',
            'sanctum-refresh-token.table',
        );
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable');
    }

    /**
     * The Sanctum access token minted alongside this generation. It is deleted
     * the moment this generation is superseded, so the relation is null for
     * every row but the family's live one.
     *
     * @return BelongsTo<Model, $this>
     */
    public function accessToken(): BelongsTo
    {
        /** @var BelongsTo<Model, $this> $relation */
        $relation = $this->belongsTo(Sanctum::personalAccessTokenModel(), 'access_token_id');

        return $relation;
    }

    /**
     * A row that can still be presented for rotation: neither rotated, nor
     * revoked, nor past either expiry.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        $now = Carbon::now();

        return $query
            ->whereNull('rotated_at')
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->where(fn (Builder $q) => $q->whereNull('family_expires_at')->orWhere('family_expires_at', '>', $now));
    }

    /**
     * Every row of one family, in generation order.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfFamily(Builder $query, string $familyUuid): Builder
    {
        return $query->where('family_uuid', $familyUuid)->orderBy('generation');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isRotated(): bool
    {
        return $this->rotated_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFamilyExpired(): bool
    {
        return $this->family_expires_at !== null && $this->family_expires_at->isPast();
    }
}
