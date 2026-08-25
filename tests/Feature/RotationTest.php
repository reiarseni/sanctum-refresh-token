<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReplayedInGracePeriod;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenRotated;
use Reiarseni\SanctumRefreshToken\Exceptions\AbilitiesEscalationException;
use Reiarseni\SanctumRefreshToken\Exceptions\FamilyExpiredException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenExpiredException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenInvalidException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenRevokedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RotationInProgressException;
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenConfig;

final class RotationTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function rotation_advances_the_family_by_one_generation(): void
    {
        $user = $this->createUser();
        $first = $this->manager()->issue($user);

        $second = $this->manager()->rotate($first->refreshToken);

        $this->assertSame($first->familyUuid, $second->familyUuid);
        $this->assertSame(2, $second->generation);

        $consumed = SanctumRefreshToken::query()->where('generation', 1)->firstOrFail();
        $created = SanctumRefreshToken::query()->where('generation', 2)->firstOrFail();

        $this->assertSame($consumed->getKey(), $created->previous_id);
    }

    #[Test]
    public function the_consumed_row_is_retained_and_marked_rotated(): void
    {
        $user = $this->createUser();
        $first = $this->manager()->issue($user);

        $this->manager()->rotate($first->refreshToken);

        $consumed = SanctumRefreshToken::query()->where('generation', 1)->firstOrFail();

        $this->assertNotNull($consumed->rotated_at);
        $this->assertSame(2, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function the_family_identity_and_absolute_expiry_survive_rotation(): void
    {
        config(['sanctum-refresh-token.expiration.family' => 60 * 24 * 30]);

        $first = $this->manager()->issue($this->createUser());

        $this->manager()->rotate($first->refreshToken);

        $rows = SanctumRefreshToken::query()->orderBy('generation')->get();

        $this->assertSame($rows[0]->family_uuid, $rows[1]->family_uuid);
        $this->assertNotNull($rows[1]->family_expires_at);
        $this->assertTrue($rows[0]->family_expires_at->eq($rows[1]->family_expires_at));
    }

    #[Test]
    public function abilities_are_carried_forward_unchanged(): void
    {
        $first = $this->manager()->issue(
            $this->createUser(),
            TokenConfig::make()->withAbilities(['orders:read', 'orders:write']),
        );

        $this->manager()->rotate($first->refreshToken);

        $created = SanctumRefreshToken::query()->where('generation', 2)->firstOrFail();

        $this->assertSame(['orders:read', 'orders:write'], $created->abilities);
        $this->assertSame(
            ['orders:read', 'orders:write'],
            Sanctum::personalAccessTokenModel()::query()->latest('id')->firstOrFail()->abilities,
        );
    }

    #[Test]
    public function rotation_can_narrow_abilities_but_not_widen_them(): void
    {
        $first = $this->manager()->issue(
            $this->createUser(),
            TokenConfig::make()->withAbilities(['orders:read', 'orders:write']),
        );

        $narrowed = $this->manager()->rotate($first->refreshToken, ['orders:read']);

        $this->assertSame(2, $narrowed->generation);

        try {
            $this->manager()->rotate($narrowed->refreshToken, ['orders:read', 'orders:write', 'admin']);
            $this->fail('Widening abilities should have been refused.');
        } catch (AbilitiesEscalationException $e) {
            $this->assertSame('abilities_escalation', $e->errorCode());
        }

        $this->assertSame(2, SanctumRefreshToken::query()->max('generation'));
    }

    #[Test]
    public function the_previous_access_token_stops_authenticating(): void
    {
        $user = $this->createUser();
        $first = $this->manager()->issue($user);

        $accessTokenId = SanctumRefreshToken::query()->firstOrFail()->access_token_id;

        $this->manager()->rotate($first->refreshToken);

        $this->assertNull(
            Sanctum::personalAccessTokenModel()::query()->find($accessTokenId),
        );
    }

    #[Test]
    public function access_tokens_of_other_families_are_unaffected(): void
    {
        $user = $this->createUser();
        $rotating = $this->manager()->issue($user);
        $untouched = $this->manager()->issue($user);

        $untouchedAccessTokenId = SanctumRefreshToken::query()
            ->where('family_uuid', $untouched->familyUuid)
            ->firstOrFail()
            ->access_token_id;

        $this->manager()->rotate($rotating->refreshToken);

        $this->assertNotNull(
            Sanctum::personalAccessTokenModel()::query()->find($untouchedAccessTokenId),
        );
    }

    #[Test]
    public function an_expired_refresh_token_is_rejected_without_revoking_the_family(): void
    {
        config(['sanctum-refresh-token.expiration.refresh_token' => 60]);

        $pair = $this->manager()->issue($this->createUser());

        Carbon::setTestNow(Carbon::now()->addMinutes(61));

        $this->assertRefusedWith(RefreshTokenExpiredException::class, 'refresh_token_expired', $pair->refreshToken);

        Carbon::setTestNow();

        $this->assertNull(SanctumRefreshToken::query()->firstOrFail()->revoked_at);
    }

    #[Test]
    public function a_family_past_its_absolute_expiry_cannot_be_rotated(): void
    {
        config([
            'sanctum-refresh-token.expiration.refresh_token' => 60 * 24 * 30,
            'sanctum-refresh-token.expiration.family' => 60,
        ]);

        $pair = $this->manager()->issue($this->createUser());

        Carbon::setTestNow(Carbon::now()->addMinutes(61));

        $this->assertRefusedWith(FamilyExpiredException::class, 'family_expired', $pair->refreshToken);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_token_from_a_revoked_family_is_rejected_as_revoked(): void
    {
        $user = $this->createUser();
        $pair = $this->manager()->issue($user);

        $this->manager()->revokeFamily($pair->familyUuid, RevocationReason::Logout);

        $exception = $this->assertRefusedWith(
            RefreshTokenRevokedException::class,
            'refresh_token_revoked',
            $pair->refreshToken,
        );

        $this->assertSame(RevocationReason::Logout, $exception->reason);
    }

    #[Test]
    public function an_unknown_or_malformed_token_is_rejected_uniformly(): void
    {
        $this->createUser();

        $malformed = $this->assertRefusedWith(
            RefreshTokenInvalidException::class,
            'refresh_token_invalid',
            'not-a-token',
        );

        $unknown = $this->assertRefusedWith(
            RefreshTokenInvalidException::class,
            'refresh_token_invalid',
            '999999|'.str_repeat('a', 64),
        );

        $this->assertSame($malformed->getMessage(), $unknown->getMessage());
    }

    #[Test]
    public function a_wrong_secret_is_indistinguishable_from_an_unknown_identifier(): void
    {
        $pair = $this->manager()->issue($this->createUser());
        [$id] = explode('|', $pair->refreshToken, 2);

        $wrongSecret = $this->assertRefusedWith(
            RefreshTokenInvalidException::class,
            'refresh_token_invalid',
            $id.'|'.str_repeat('b', 64),
        );

        $unknownId = $this->assertRefusedWith(
            RefreshTokenInvalidException::class,
            'refresh_token_invalid',
            '424242|'.str_repeat('b', 64),
        );

        $this->assertSame($wrongSecret->getMessage(), $unknownId->getMessage());
    }

    #[Test]
    public function a_replay_inside_the_grace_window_leaves_the_family_intact(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 10]);

        Event::fake([RefreshTokenReplayedInGracePeriod::class]);

        $user = $this->createUser();
        $first = $this->manager()->issue($user);

        Carbon::setTestNow($start = Carbon::parse('2026-03-01 12:00:00'));
        $this->manager()->rotate($first->refreshToken);

        Carbon::setTestNow($start->copy()->addSeconds(2));

        $exception = $this->assertRefusedWith(
            RotationInProgressException::class,
            'rotation_in_progress',
            $first->refreshToken,
        );

        Carbon::setTestNow();

        $this->assertSame($first->familyUuid, $exception->familyUuid);
        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());
        $this->assertSame(2, SanctumRefreshToken::query()->max('generation'));

        Event::assertDispatched(
            RefreshTokenReplayedInGracePeriod::class,
            static fn (RefreshTokenReplayedInGracePeriod $event): bool => $event->familyUuid === $first->familyUuid
                && $event->secondsSinceRotation >= 2.0,
        );
    }

    #[Test]
    public function a_zero_grace_period_restores_strict_rotation(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);

        $user = $this->createUser();
        $first = $this->manager()->issue($user);

        $this->manager()->rotate($first->refreshToken);

        $this->assertRefusedWith(
            RefreshTokenReusedException::class,
            'refresh_token_reused',
            $first->refreshToken,
        );

        $this->assertSame(
            0,
            SanctumRefreshToken::query()->whereNull('revoked_at')->count(),
            'A strict configuration should have revoked every row of the family.',
        );
    }

    #[Test]
    public function rotation_dispatches_an_event_carrying_no_plaintext(): void
    {
        Event::fake([RefreshTokenRotated::class]);

        $first = $this->manager()->issue($this->createUser());
        $second = $this->manager()->rotate($first->refreshToken);

        Event::assertDispatched(RefreshTokenRotated::class, function (RefreshTokenRotated $event) use ($first, $second): bool {
            $this->assertSame($first->familyUuid, $event->familyUuid);
            $this->assertSame(2, $event->generation);
            $this->assertSame(1, $event->previousGeneration);

            foreach (get_object_vars($event) as $value) {
                $this->assertNotSame($first->refreshToken, $value);
                $this->assertNotSame($second->refreshToken, $value);
                $this->assertNotSame($second->accessToken, $value);
            }

            return true;
        });
    }

    /**
     * @template T of SanctumRefreshTokenException
     *
     * @param  class-string<T>  $expected
     * @return T
     */
    private function assertRefusedWith(string $expected, string $code, string $token): SanctumRefreshTokenException
    {
        try {
            $this->manager()->rotate($token);
        } catch (SanctumRefreshTokenException $e) {
            $this->assertInstanceOf($expected, $e);
            $this->assertSame($code, $e->errorCode());

            // No refusal ever echoes the token it was given back at the caller.
            $this->assertStringNotContainsString($token, $e->getMessage());

            /** @var T $e */
            return $e;
        }

        $this->fail(sprintf('Expected the rotation to be refused with [%s].', $code));
    }
}
