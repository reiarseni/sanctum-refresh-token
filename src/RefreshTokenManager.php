<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken;

use DateTimeInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Contracts\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\Sanctum;
use Reiarseni\SanctumRefreshToken\Context\ContextResolverFactory;
use Reiarseni\SanctumRefreshToken\Enums\ReuseStrategy;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Events\ContextMismatchDetected;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenIssued;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReplayedInGracePeriod;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReuseDetected;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenRotated;
use Reiarseni\SanctumRefreshToken\Events\TokenFamilyRevoked;
use Reiarseni\SanctumRefreshToken\Exceptions\AbilitiesEscalationException;
use Reiarseni\SanctumRefreshToken\Exceptions\ContextMismatchException;
use Reiarseni\SanctumRefreshToken\Exceptions\FamilyExpiredException;
use Reiarseni\SanctumRefreshToken\Exceptions\InvalidSessionLabelException;
use Reiarseni\SanctumRefreshToken\Exceptions\InvalidTokenableException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenExpiredException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenInvalidException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenRevokedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RotationInProgressException;
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;
use Reiarseni\SanctumRefreshToken\Observability\GraceReplayRecorder;
use Reiarseni\SanctumRefreshToken\Support\MetadataHasher;
use Reiarseni\SanctumRefreshToken\Support\Settings;
use Reiarseni\SanctumRefreshToken\Support\TokenSecret;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenConfig;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenPair;

/**
 * Opens, advances and closes token families.
 *
 * Two operations matter here and everything else supports them.
 *
 * `issue()` opens a family: one row at generation 1, holding the family's
 * identity, its absolute expiry, its abilities and the context it was issued
 * in.
 *
 * `rotate()` advances one. It runs inside a transaction and takes an exclusive
 * row lock on the presented row *before* reading its state, which is what stops
 * two concurrent refreshes from both passing the "not yet rotated" check and
 * forking the family. Everything the rotation decides — benign retry, reuse,
 * context mismatch — is decided under that lock.
 *
 * One subtlety worth stating, because it looks like a mistake otherwise: the
 * failure paths inside the transaction *return* their exception rather than
 * throwing it, and the caller throws it after the transaction commits. Reuse
 * detection and strict context handling both revoke rows, and throwing from
 * inside the transaction would roll that revocation straight back — the family
 * would be reported as dead while remaining alive in the database.
 */
class RefreshTokenManager
{
    /** The generation whose row carries the family's lock. */
    public const ANCHOR_GENERATION = 1;

    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
        private readonly ContextResolverFactory $contextResolvers,
        private readonly MetadataHasher $metadata,
        private readonly GraceReplayRecorder $graceReplays,
        private readonly Settings $settings,
    ) {}

    /**
     * Open a new token family and mint its first pair.
     *
     * @throws InvalidTokenableException when the model cannot hold Sanctum tokens
     * @throws InvalidSessionLabelException when the session label is unusable
     */
    public function issue(Model $tokenable, ?TokenConfig $tokenConfig = null): TokenPair
    {
        if (! SanctumRefreshToken::isTokenable($tokenable)) {
            throw InvalidTokenableException::missingHasApiTokens($tokenable::class);
        }

        $tokenConfig ??= TokenConfig::make();
        $now = Carbon::now();

        $name = $this->validateLabel($tokenConfig->name ?? $this->defaultLabel());
        $abilities = $tokenConfig->abilities ?? $this->defaultAbilities();

        $accessExpiresAt = $this->resolveExpiry($tokenConfig->accessTokenExpiresAt, 'access_token', $now);
        $refreshExpiresAt = $this->resolveExpiry($tokenConfig->refreshTokenExpiresAt, 'refresh_token', $now);
        $familyExpiresAt = $tokenConfig->familyExpiresAt !== null
            ? Carbon::instance($tokenConfig->familyExpiresAt)
            : $this->resolveExpiry(null, 'family', $now);

        $context = $this->resolveContext();

        return $this->connection()->transaction(function () use (
            $tokenable, $name, $abilities, $accessExpiresAt, $refreshExpiresAt, $familyExpiresAt, $context, $now
        ): TokenPair {
            $this->enforceFamilyLimit($tokenable);

            $familyUuid = (string) Str::uuid();
            $accessToken = $this->createAccessToken($tokenable, $name, $abilities, $accessExpiresAt);

            $row = $this->persist([
                'family_uuid' => $familyUuid,
                'tokenable_type' => $tokenable->getMorphClass(),
                'tokenable_id' => $tokenable->getKey(),
                'access_token_id' => $accessToken->accessToken->getKey(),
                'name' => $name,
                'abilities' => $abilities,
                'previous_id' => null,
                'generation' => 1,
                'expires_at' => $refreshExpiresAt,
                'family_expires_at' => $familyExpiresAt,
                'last_used_at' => $now,
            ], $context);

            $this->events->dispatch(new RefreshTokenIssued($tokenable, $familyUuid, 1));

            return new TokenPair(
                accessToken: $accessToken->plainTextToken,
                refreshToken: $row['plaintext'],
                accessTokenExpiresAt: $accessExpiresAt,
                refreshTokenExpiresAt: $refreshExpiresAt,
                familyUuid: $familyUuid,
                generation: 1,
                familyExpiresAt: $familyExpiresAt,
            );
        });
    }

    /**
     * Exchange a refresh token for the next generation of its family.
     *
     * @param  list<string>|null  $abilities  a narrowing of the family's abilities; never a widening
     *
     * @throws SanctumRefreshTokenException on every refusal, each with its own code
     */
    public function rotate(string $plaintextRefreshToken, ?array $abilities = null): TokenPair
    {
        $parsed = TokenSecret::parse($plaintextRefreshToken);

        // A token that does not even have the right shape is refused here, so
        // no query runs and no secret comparison is attempted.
        if ($parsed === null) {
            throw RefreshTokenInvalidException::make();
        }

        [$id, $secret] = $parsed;

        $outcome = $this->connection()->transaction(
            fn (): TokenPair|SanctumRefreshTokenException => $this->attemptRotation($id, $secret, $abilities),
        );

        if ($outcome instanceof SanctumRefreshTokenException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * Revoke every live row of a family and every access token it minted.
     */
    public function revokeFamily(string $familyUuid, RevocationReason $reason = RevocationReason::Revoked): bool
    {
        return $this->connection()->transaction(
            fn (): bool => $this->revokeFamilyRows($familyUuid, $reason),
        );
    }

    /**
     * Revoke every family a tokenable holds: a log-out-everywhere.
     *
     * @return int the number of families revoked
     */
    public function revokeAllFamilies(Model $tokenable, RevocationReason $reason = RevocationReason::Logout): int
    {
        $families = $this->queryFor($tokenable)
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('family_uuid')
            ->all();

        $revoked = 0;

        foreach ($families as $familyUuid) {
            if ($this->revokeFamily(self::asString($familyUuid), $reason)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    /**
     * Revoke the family a given refresh token belongs to. Used by a logout
     * endpoint, which holds the refresh token rather than the family id.
     */
    public function revokeByRefreshToken(string $plaintextRefreshToken, RevocationReason $reason = RevocationReason::Logout): bool
    {
        $row = $this->resolve($plaintextRefreshToken);

        if ($row === null) {
            throw RefreshTokenInvalidException::make();
        }

        return $this->revokeFamily($row->family_uuid, $reason);
    }

    /**
     * Resolve a plaintext refresh token to its row without locking or mutating
     * anything. Returns null for malformed, unknown and wrong-secret alike.
     */
    public function resolve(string $plaintextRefreshToken): ?RefreshToken
    {
        $parsed = TokenSecret::parse($plaintextRefreshToken);

        if ($parsed === null) {
            return null;
        }

        [$id, $secret] = $parsed;

        $row = $this->query()->whereKey($id)->first();

        if ($row === null || ! TokenSecret::verify($secret, $row->token)) {
            return null;
        }

        return $row;
    }

    /**
     * The body of a rotation, executed under the row lock.
     *
     * Returns the exception to throw rather than throwing it; see the class
     * docblock for why.
     *
     * @param  list<string>|null  $requestedAbilities
     */
    private function attemptRotation(string $id, string $secret, ?array $requestedAbilities): TokenPair|SanctumRefreshTokenException
    {
        // Read without a lock. This looks like a wasted query and is not: no
        // row of a family may be locked before the family's anchor, or two
        // transactions holding different generations deadlock waiting for each
        // other. All this read does is identify the family and check the
        // secret; the authoritative read happens under the anchor below.
        $row = $this->query()->whereKey($id)->first();

        // Unknown identifier and wrong secret produce the same refusal, so the
        // endpoint cannot be used to learn which identifiers exist.
        if ($row === null || ! TokenSecret::verify($secret, $row->token)) {
            return RefreshTokenInvalidException::make();
        }

        // Serialise on the family, not on the presented row.
        //
        // Locking the presented row alone stops two rotations of the *same*
        // token from forking, and nothing else: a replay of generation N and a
        // rotation of generation N+1 touch different rows, so both proceed, and
        // the revocation can commit before the generation the other transaction
        // is creating exists to be revoked -- leaving a live token inside a
        // family the package has just declared compromised.
        $this->lockFamilyAnchor($row->family_uuid);

        // Re-read as a locking read, not a plain one. Under MySQL's default
        // REPEATABLE READ a plain SELECT is answered from the snapshot the
        // transaction opened with, so it would not see a revocation another
        // transaction committed while this one waited for the anchor.
        $row = $this->query()->whereKey($id)->lockForUpdate()->first();

        if ($row === null) {
            return RefreshTokenInvalidException::make();
        }

        $tokenable = $row->tokenable;

        if (! $tokenable instanceof Model) {
            return RefreshTokenInvalidException::make();
        }

        if ($row->isRevoked()) {
            return RefreshTokenRevokedException::make($row->revocation_reason);
        }

        // Checked before expiry: a replayed token is evidence of a compromise
        // whether or not it has since expired, and that reading has to win.
        if ($row->isRotated()) {
            return $this->handleReplay($row, $tokenable);
        }

        if ($row->isExpired()) {
            return RefreshTokenExpiredException::make();
        }

        if ($row->isFamilyExpired()) {
            return FamilyExpiredException::make();
        }

        $contextRefusal = $this->verifyContext($row, $tokenable);

        if ($contextRefusal !== null) {
            return $contextRefusal;
        }

        $granted = $row->abilities ?? $this->defaultAbilities();
        $abilities = $requestedAbilities ?? $granted;

        if (! $this->isSubsetOfAbilities($abilities, $granted)) {
            return AbilitiesEscalationException::make($abilities, $granted);
        }

        return $this->advance($row, $tokenable, $abilities);
    }

    /**
     * Mint the next generation and retire the one being replaced.
     *
     * @param  list<string>  $abilities
     */
    private function advance(RefreshToken $row, Model $tokenable, array $abilities): TokenPair
    {
        $now = Carbon::now();

        // The superseded access token dies inside this transaction. Leaving it
        // alive would keep a credential the system has already replaced usable
        // until its natural expiry, which is exactly the window an attacker who
        // triggered the rotation would exploit.
        $this->deleteAccessTokens([$row->access_token_id]);

        $accessExpiresAt = $this->resolveExpiry(null, 'access_token', $now);
        $refreshExpiresAt = $this->resolveExpiry(null, 'refresh_token', $now);

        $accessToken = $this->createAccessToken($tokenable, $row->name, $abilities, $accessExpiresAt);

        $generation = $row->generation + 1;

        $new = $this->persist([
            'family_uuid' => $row->family_uuid,
            'tokenable_type' => $row->tokenable_type,
            'tokenable_id' => $row->tokenable_id,
            'access_token_id' => $accessToken->accessToken->getKey(),
            'name' => $row->name,
            'abilities' => $abilities,
            'previous_id' => $row->getKey(),
            'generation' => $generation,
            'expires_at' => $refreshExpiresAt,
            // Carried forward unchanged: no number of rotations extends the
            // family past its absolute cap.
            'family_expires_at' => $row->family_expires_at,
            'last_used_at' => $now,
        ], $this->carriedContext($row));

        $row->forceFill(['rotated_at' => $now, 'last_used_at' => $now])->save();

        $this->events->dispatch(new RefreshTokenRotated(
            $tokenable,
            $row->family_uuid,
            $generation,
            $row->generation,
        ));

        return new TokenPair(
            accessToken: $accessToken->plainTextToken,
            refreshToken: $new['plaintext'],
            accessTokenExpiresAt: $accessExpiresAt,
            refreshTokenExpiresAt: $refreshExpiresAt,
            familyUuid: $row->family_uuid,
            generation: $generation,
            familyExpiresAt: $row->family_expires_at,
        );
    }

    /**
     * A consumed token was presented again. Only the elapsed time separates a
     * lost-response retry from a stolen credential.
     */
    private function handleReplay(RefreshToken $row, Model $tokenable): SanctumRefreshTokenException
    {
        $rotatedAt = $row->rotated_at ?? Carbon::now();
        $elapsed = max(0.0, (float) (Carbon::now()->getPreciseTimestamp(3) - $rotatedAt->getPreciseTimestamp(3)) / 1000);
        $grace = $this->graceSeconds();

        if ($grace > 0 && $elapsed <= $grace) {
            $this->events->dispatch(new RefreshTokenReplayedInGracePeriod(
                $tokenable,
                $row->family_uuid,
                $row->generation,
                $elapsed,
            ));

            $this->graceReplays->record($row, $elapsed);

            return RotationInProgressException::make($row->family_uuid, $elapsed);
        }

        $highest = $this->query()
            ->where('family_uuid', $row->family_uuid)
            ->max('generation');

        $currentGeneration = is_numeric($highest) ? (int) $highest : $row->generation;

        $this->events->dispatch(new RefreshTokenReuseDetected(
            $tokenable,
            $row->family_uuid,
            $row->generation,
            $currentGeneration,
            $elapsed,
        ));

        match ($this->reuseStrategy()) {
            // The legitimate holder's current generation dies too. That is the
            // point: after a fork, the package cannot tell which of the two
            // parties is the owner, so it ends the family and makes both
            // authenticate again.
            ReuseStrategy::RevokeFamily => $this->revokeFamilyRows($row->family_uuid, RevocationReason::ReuseDetected),
            ReuseStrategy::RevokeToken => $this->revokeRows([$row], RevocationReason::ReuseDetected),
            ReuseStrategy::Observe => null,
        };

        return RefreshTokenReusedException::make($row->family_uuid, $row->generation, $currentGeneration);
    }

    /**
     * Compare the context the family was issued in against the current one.
     *
     * This is a plain value comparison performed by the package, not an Eloquent
     * global scope. A scope that resolves its value from the container returns
     * nothing when the container has nothing to give, and a scope that filters
     * on nothing filters nothing — it fails open. This cannot.
     */
    private function verifyContext(RefreshToken $row, Model $tokenable): ?ContextMismatchException
    {
        if (! $this->contextResolvers->bindingEnabled()) {
            return null;
        }

        $recorded = $row->getAttribute($this->contextColumn());

        // A family issued before binding was switched on carries no context and
        // is not bound by one.
        if ($recorded === null || $recorded === '') {
            return null;
        }

        $recorded = self::asString($recorded);
        $resolved = $this->resolveContext();

        if ($resolved !== null && hash_equals($recorded, $resolved)) {
            return null;
        }

        $this->events->dispatch(new ContextMismatchDetected(
            $tokenable,
            $row->family_uuid,
            $recorded,
            $resolved,
        ));

        if ($this->settings->string('sanctum-refresh-token.context.on_mismatch', 'reject') === 'revoke_family') {
            $this->revokeFamilyRows($row->family_uuid, RevocationReason::ContextMismatch);
        }

        return ContextMismatchException::make($recorded, $resolved);
    }

    /**
     * Take the family's exclusive lock.
     *
     * Generation 1 is the anchor: it exists for the whole life of the family,
     * is never created twice, and is reachable by index. Locking it serialises
     * every operation that mutates the family against every other, at the cost
     * of one indexed row read -- where locking every generation cost one read
     * per rotation the family had ever performed.
     *
     * Pruning exempts this row while any of its family is still rotatable, so
     * a live family always has an anchor to lock.
     */
    private function lockFamilyAnchor(string $familyUuid): void
    {
        $this->query()
            ->where('family_uuid', $familyUuid)
            ->where('generation', self::ANCHOR_GENERATION)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Revoke every not-yet-revoked row of a family, and delete every access
     * token any of its generations minted.
     */
    private function revokeFamilyRows(string $familyUuid, RevocationReason $reason): bool
    {
        // Whoever calls this outside a rotation has not locked the family yet.
        // Locking here too is cheap and makes the revocation atomic against a
        // rotation that is midway through appending a generation.
        $this->lockFamilyAnchor($familyUuid);

        /** @var list<RefreshToken> $rows */
        $rows = $this->query()
            ->where('family_uuid', $familyUuid)
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get()
            ->all();

        return $this->revokeRows($rows, $reason);
    }

    /**
     * @param  list<RefreshToken>  $rows
     */
    private function revokeRows(array $rows, RevocationReason $reason): bool
    {
        if ($rows === []) {
            return false;
        }

        $now = Carbon::now();

        $this->deleteAccessTokens(array_map(static fn (RefreshToken $row): ?int => $row->access_token_id, $rows));

        $this->query()
            ->whereIn('id', array_map(static fn (RefreshToken $row): mixed => $row->getKey(), $rows))
            ->update([
                'revoked_at' => $now,
                'revocation_reason' => $reason->value,
                'updated_at' => $now,
            ]);

        $first = $rows[0];
        $tokenable = $first->tokenable;

        if ($tokenable instanceof Model) {
            $this->events->dispatch(new TokenFamilyRevoked($tokenable, $first->family_uuid, $reason));
        }

        return true;
    }

    /**
     * @param  array<int, int|null>  $ids
     */
    private function deleteAccessTokens(array $ids): void
    {
        $ids = array_values(array_filter($ids, static fn (?int $id): bool => $id !== null));

        if ($ids === []) {
            return;
        }

        Sanctum::personalAccessTokenModel()::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Hold the tokenable to the configured number of live families, retiring
     * the least recently used one when it would be exceeded.
     */
    private function enforceFamilyLimit(Model $tokenable): void
    {
        $limit = $this->settings->nullableInt('sanctum-refresh-token.rotation.max_concurrent_families');

        if ($limit === null || $limit < 1) {
            return;
        }

        $families = $this->queryFor($tokenable)
            ->live()
            ->orderBy('last_used_at')
            ->get(['family_uuid', 'last_used_at'])
            ->unique('family_uuid')
            ->values();

        $excess = $families->count() - $limit + 1;

        for ($i = 0; $i < $excess; $i++) {
            $family = $families->get($i);

            if ($family === null) {
                break;
            }

            $this->revokeFamilyRows((string) $family->family_uuid, RevocationReason::FamilyLimit);
        }
    }

    /**
     * Write one generation, returning the attributes plus the plaintext token
     * that only exists during this call.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{row: RefreshToken, plaintext: string}
     */
    private function persist(array $attributes, ?string $context): array
    {
        $secret = TokenSecret::generate($this->secretBytes());

        $row = SanctumRefreshToken::newRefreshToken();
        $row->forceFill($attributes + [
            'token' => TokenSecret::hash($secret),
            'ip_hash' => $this->metadata->prepare($this->currentIp()),
            'user_agent_hash' => $this->metadata->prepare($this->currentUserAgent()),
        ]);

        if ($context !== null) {
            $row->setAttribute($this->contextColumn(), $context);
        }

        $row->save();

        return ['row' => $row, 'plaintext' => TokenSecret::format(self::asString($row->getKey()), $secret)];
    }

    /**
     * @param  list<string>  $abilities
     */
    private function createAccessToken(Model $tokenable, string $name, array $abilities, ?DateTimeInterface $expiresAt): NewAccessToken
    {
        // Checked rather than asserted: a tokenable reaches rotation from a
        // stored morph class, and nothing guarantees the model still holds
        // Sanctum's trait. Most applications use that trait without declaring
        // its contract, so the duck-typed branch is the common path, not the
        // exotic one.
        if ($tokenable instanceof HasApiTokens) {
            return $tokenable->createToken($name, $abilities, $expiresAt);
        }

        if (! method_exists($tokenable, 'createToken')) {
            throw InvalidTokenableException::missingHasApiTokens($tokenable::class);
        }

        $token = $tokenable->createToken($name, $abilities, $expiresAt);

        if (! $token instanceof NewAccessToken) {
            throw InvalidTokenableException::missingHasApiTokens($tokenable::class);
        }

        return $token;
    }

    /**
     * The context recorded on a family is copied forward verbatim: rotation
     * never re-resolves it, or a user whose tenant changed would silently drag
     * an old family into a new context. That holds whether or not binding is
     * switched on today -- otherwise turning it off, rotating, and turning it
     * back on would unbind every family that rotated in between.
     *
     * When the column is not in the schema Eloquent never hydrates it, so the
     * absent attribute answers the question without asking the database.
     */
    private function carriedContext(RefreshToken $row): ?string
    {
        $value = $row->getAttribute($this->contextColumn());

        return $value === null ? null : self::asString($value);
    }

    private function resolveContext(): ?string
    {
        if (! $this->contextResolvers->bindingEnabled()) {
            return null;
        }

        return $this->contextResolvers->make()->resolve();
    }

    private function contextColumn(): string
    {
        return $this->settings->string('sanctum-refresh-token.context.column', 'context');
    }

    /**
     * Narrow a value Eloquent hands back as `mixed` to a string.
     *
     * Column values and primary keys are `mixed` to the type system however
     * well the schema is known, and the package only ever reads columns it
     * declared itself: a uuid, a context discriminator, an id.
     */
    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * `*` grants everything, so any request is a narrowing of it. Otherwise the
     * requested set has to be contained in the granted one.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $granted
     */
    private function isSubsetOfAbilities(array $requested, array $granted): bool
    {
        if (in_array('*', $granted, true)) {
            return true;
        }

        return array_diff($requested, $granted) === [];
    }

    private function validateLabel(string $label): string
    {
        $max = $this->settings->int('sanctum-refresh-token.session.max_label_length', 100, 1);

        if (preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            throw InvalidSessionLabelException::controlCharacters();
        }

        $length = mb_strlen($label);

        if ($length > $max) {
            throw InvalidSessionLabelException::tooLong($length, $max);
        }

        return $label;
    }

    private function defaultLabel(): string
    {
        return $this->settings->string('sanctum-refresh-token.session.default_label', 'Unnamed device');
    }

    /**
     * @return list<string>
     */
    private function defaultAbilities(): array
    {
        return $this->settings->stringList('sanctum-refresh-token.rotation.default_abilities', ['*']);
    }

    private function graceSeconds(): int
    {
        return $this->settings->int('sanctum-refresh-token.rotation.reuse_grace_period', 10);
    }

    private function reuseStrategy(): ReuseStrategy
    {
        $configured = $this->settings->raw('sanctum-refresh-token.rotation.reuse_strategy');

        if ($configured instanceof ReuseStrategy) {
            return $configured;
        }

        return is_string($configured)
            ? ReuseStrategy::tryFrom($configured) ?? ReuseStrategy::RevokeFamily
            : ReuseStrategy::RevokeFamily;
    }

    private function secretBytes(): int
    {
        return $this->settings->int(
            'sanctum-refresh-token.security.secret_bytes',
            TokenSecret::MINIMUM_BYTES,
            TokenSecret::MINIMUM_BYTES,
        );
    }

    /**
     * An explicit override wins; otherwise the configured number of minutes is
     * measured from now. A null configured value means "no expiry".
     */
    private function resolveExpiry(?DateTimeInterface $override, string $key, Carbon $now): ?Carbon
    {
        if ($override !== null) {
            return Carbon::instance($override);
        }

        $minutes = $this->settings->nullableInt("sanctum-refresh-token.expiration.{$key}");

        // A null configured lifetime is not "zero minutes": it means the thing
        // it governs does not expire at all.
        if ($minutes === null) {
            return null;
        }

        return $now->copy()->addMinutes($minutes);
    }

    private function currentIp(): ?string
    {
        $request = $this->currentRequest();

        return $request?->ip();
    }

    private function currentUserAgent(): ?string
    {
        $request = $this->currentRequest();
        $agent = $request?->userAgent();

        return is_string($agent) && $agent !== '' ? $agent : null;
    }

    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');

        return $request instanceof Request ? $request : null;
    }

    /**
     * @return Builder<RefreshToken>
     */
    private function query(): Builder
    {
        return SanctumRefreshToken::query();
    }

    /**
     * @return Builder<RefreshToken>
     */
    private function queryFor(Model $tokenable): Builder
    {
        return $this->query()
            ->where('tokenable_type', $tokenable->getMorphClass())
            ->where('tokenable_id', $tokenable->getKey());
    }

    private function connection(): ConnectionInterface
    {
        return SanctumRefreshToken::newRefreshToken()->getConnection();
    }
}
