<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Sessions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Reiarseni\SanctumRefreshToken\Context\ContextResolverFactory;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Exceptions\InvalidSessionLabelException;
use Reiarseni\SanctumRefreshToken\Exceptions\SessionNotFoundException;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Support\Settings;
use Reiarseni\SanctumRefreshToken\ValueObjects\Device;
use Reiarseni\SanctumRefreshToken\ValueObjects\Session;

/**
 * The read model over token families, and the operations that manage them.
 *
 * A family is a session. Its live generation carries everything a "your
 * devices" screen needs — label, device, recency — and this class assembles
 * that into immutable Session objects so an application never has to query the
 * package's table itself.
 */
class SessionManager
{
    private ?Model $tokenable = null;

    public function __construct(
        private readonly Container $container,
        private readonly RefreshTokenManager $manager,
        private readonly ContextResolverFactory $contextResolvers,
        private readonly Settings $settings,
    ) {}

    /**
     * Bind this manager to a tokenable. Returns a clone, so the container's
     * shared instance is never bound to whoever asked first.
     */
    public function for(Model $tokenable): self
    {
        $clone = clone $this;
        $clone->tokenable = $tokenable;

        return $clone;
    }

    /**
     * Every live session, most recently used first.
     *
     * @return Collection<int, Session>
     */
    public function all(): Collection
    {
        $currentFamily = $this->currentFamilyUuid();

        return $this->liveRows()
            ->map(fn (RefreshToken $row): Session => $this->toSession($row, $currentFamily))
            ->values();
    }

    /**
     * The session whose access token authenticated this request, or null
     * outside an authenticated context.
     */
    public function current(): ?Session
    {
        $familyUuid = $this->currentFamilyUuid();

        if ($familyUuid === null) {
            return null;
        }

        return $this->all()->first(static fn (Session $session): bool => $session->familyUuid === $familyUuid);
    }

    /**
     * Rename a session. Only the label changes.
     *
     * @throws SessionNotFoundException when the family is not this tokenable's
     * @throws InvalidSessionLabelException when the label is unusable
     */
    public function rename(string $familyUuid, string $label): Session
    {
        $row = $this->liveRows()->first(
            static fn (RefreshToken $candidate): bool => $candidate->family_uuid === $familyUuid,
        );

        if ($row === null) {
            throw SessionNotFoundException::make($familyUuid);
        }

        $this->assertValidLabel($label);

        // Every generation of the family carries the label, so a later rotation
        // does not resurrect the old one.
        $this->query()->where('family_uuid', $familyUuid)->update(['name' => $label]);

        $row->refresh();

        return $this->toSession($row, $this->currentFamilyUuid());
    }

    /**
     * Revoke one session: every refresh token and every access token of that
     * family.
     *
     * @throws SessionNotFoundException when the family is not this tokenable's
     */
    public function revoke(string $familyUuid, RevocationReason $reason = RevocationReason::Revoked): bool
    {
        // Ownership is checked against this tokenable before anything is
        // revoked, so one user cannot end another user's session by guessing a
        // family identifier.
        $owned = $this->scopedQuery()
            ->where('family_uuid', $familyUuid)
            ->exists();

        if (! $owned) {
            throw SessionNotFoundException::make($familyUuid);
        }

        return $this->manager->revokeFamily($familyUuid, $reason);
    }

    /**
     * Log the tokenable out everywhere.
     *
     * @return int the number of sessions revoked
     */
    public function revokeAll(RevocationReason $reason = RevocationReason::Logout): int
    {
        $revoked = 0;

        foreach ($this->liveRows() as $row) {
            if ($this->manager->revokeFamily($row->family_uuid, $reason)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    /**
     * Log the tokenable out of every session but this one.
     *
     * @return int the number of sessions revoked
     */
    public function revokeOthers(RevocationReason $reason = RevocationReason::Logout): int
    {
        $current = $this->currentFamilyUuid();
        $revoked = 0;

        foreach ($this->liveRows() as $row) {
            if ($row->family_uuid === $current) {
                continue;
            }

            if ($this->manager->revokeFamily($row->family_uuid, $reason)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    /**
     * The live generation of each of the tokenable's live families, ordered by
     * recency of use.
     *
     * @return Collection<int, RefreshToken>
     */
    private function liveRows(): Collection
    {
        /** @var Collection<int, RefreshToken> $rows */
        $rows = $this->scopedQuery()
            ->live()
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();

        // One session per family: the newest live generation represents it.
        return $rows->unique('family_uuid')->values();
    }

    private function toSession(RefreshToken $row, ?string $currentFamilyUuid): Session
    {
        return new Session(
            familyUuid: $row->family_uuid,
            label: $row->name,
            device: $this->device($row),
            isCurrent: $currentFamilyUuid !== null && $row->family_uuid === $currentFamilyUuid,
            generation: $row->generation,
            createdAt: $row->created_at,
            lastUsedAt: $row->last_used_at,
            expiresAt: $row->expires_at,
            familyExpiresAt: $row->family_expires_at,
        );
    }

    /**
     * Readable device metadata only exists when plaintext storage was on when
     * the row was written; a keyed hash is deliberately not reversible.
     */
    private function device(RefreshToken $row): Device
    {
        $plaintext = $this->settings->bool('sanctum-refresh-token.security.store_metadata_plaintext');

        if (! $plaintext) {
            return Device::unavailable();
        }

        return Device::fromMetadata($row->ip_hash, $row->user_agent_hash);
    }

    /**
     * Which family the access token authenticating this request belongs to.
     *
     * Derived from request state, not from a column, which is precisely why the
     * session read model exists.
     */
    private function currentFamilyUuid(): ?string
    {
        $tokenable = $this->tokenable();

        if (! method_exists($tokenable, 'currentAccessToken')) {
            return null;
        }

        $accessToken = $tokenable->currentAccessToken();

        if (! $accessToken instanceof Model) {
            return null;
        }

        $row = $this->scopedQuery()
            ->where('access_token_id', $accessToken->getKey())
            ->whereNull('revoked_at')
            ->first();

        return $row?->family_uuid;
    }

    private function assertValidLabel(string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw InvalidSessionLabelException::controlCharacters();
        }

        $max = $this->settings->int('sanctum-refresh-token.session.max_label_length', 100, 1);
        $length = mb_strlen($label);

        if ($length > $max) {
            throw InvalidSessionLabelException::tooLong($length, $max);
        }
    }

    /**
     * @return Builder<RefreshToken>
     */
    private function query(): Builder
    {
        return SanctumRefreshToken::query();
    }

    /**
     * The tokenable's rows, narrowed to the current issuance context when
     * binding is on — a session issued in another tenant is not this tenant's
     * to list or to end.
     *
     * @return Builder<RefreshToken>
     */
    private function scopedQuery(): Builder
    {
        $tokenable = $this->tokenable();

        $query = $this->query()
            ->where('tokenable_type', $tokenable->getMorphClass())
            ->where('tokenable_id', $tokenable->getKey());

        if (! $this->contextResolvers->bindingEnabled()) {
            return $query;
        }

        $column = $this->settings->string('sanctum-refresh-token.context.column', 'context');

        if (! $this->hasContextColumn($column)) {
            return $query;
        }

        $context = $this->contextResolvers->make()->resolve();

        return $query->where(function (Builder $inner) use ($column, $context): void {
            // Families opened before binding was switched on carry no context
            // and belong to whoever holds their tokens.
            $inner->whereNull($column);

            if ($context !== null) {
                $inner->orWhere($column, $context);
            }
        });
    }

    private function hasContextColumn(string $column): bool
    {
        /** @var \Illuminate\Database\Schema\Builder $schema */
        $schema = $this->container->make('db')->connection(
            SanctumRefreshToken::newRefreshToken()->getConnectionName(),
        )->getSchemaBuilder();

        return $schema->hasColumn(SanctumRefreshToken::newRefreshToken()->getTable(), $column);
    }

    private function tokenable(): Model
    {
        if ($this->tokenable === null) {
            throw new LogicException('The session manager has not been bound to a tokenable; call for($tokenable) first.');
        }

        return $this->tokenable;
    }
}
