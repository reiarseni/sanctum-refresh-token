<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Sessions\SessionManager;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenPair;

/**
 * Convenience surface on the tokenable model.
 *
 * Everything here delegates to RefreshTokenManager and SessionManager; the
 * trait exists so that a controller can write `$user->issueTokenPair()` instead
 * of resolving the manager by hand. Use it alongside Sanctum's own
 * `HasApiTokens`, which stays responsible for access tokens.
 *
 * @mixin Model
 */
trait HasRefreshTokens
{
    /**
     * Every generation of every family this model holds, live or not.
     *
     * @return MorphMany<RefreshToken, $this>
     */
    public function refreshTokens(): MorphMany
    {
        /** @var MorphMany<RefreshToken, $this> $relation */
        $relation = $this->morphMany(SanctumRefreshToken::refreshTokenModel(), 'tokenable');

        return $relation;
    }

    /**
     * Open a family and mint its first pair.
     *
     * @param  list<string>|null  $abilities
     */
    public function issueTokenPair(
        ?string $name = null,
        ?array $abilities = null,
        ?DateTimeInterface $accessTokenExpiresAt = null,
        ?DateTimeInterface $refreshTokenExpiresAt = null,
        ?DateTimeInterface $familyExpiresAt = null,
    ): TokenPair {
        return app(RefreshTokenManager::class)->issue(
            $this,
            $name,
            $abilities,
            $accessTokenExpiresAt,
            $refreshTokenExpiresAt,
            $familyExpiresAt,
        );
    }

    /**
     * The session read model for this tokenable.
     */
    public function sessions(): SessionManager
    {
        return app(SessionManager::class)->for($this);
    }

    /**
     * Log this model out everywhere: every family and every access token.
     */
    public function revokeAllTokenFamilies(RevocationReason $reason = RevocationReason::Logout): int
    {
        return app(RefreshTokenManager::class)->revokeAllFamilies($this, $reason);
    }
}
