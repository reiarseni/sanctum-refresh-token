<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenIssued;
use Reiarseni\SanctumRefreshToken\Exceptions\InvalidTokenableException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\Fixtures\PlainModel;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenConfig;

final class IssuanceTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function a_first_issuance_opens_a_family_at_generation_one(): void
    {
        $user = $this->createUser();

        $pair = $this->manager()->issue($user);

        $row = SanctumRefreshToken::query()->firstOrFail();

        $this->assertSame(1, $row->generation);
        $this->assertNull($row->previous_id);
        $this->assertNull($row->rotated_at);
        $this->assertNull($row->revoked_at);
        $this->assertNotSame('', $row->family_uuid);
        $this->assertSame($row->family_uuid, $pair->familyUuid);
    }

    #[Test]
    public function two_logins_open_independent_families(): void
    {
        $user = $this->createUser();

        $first = $this->manager()->issue($user);
        $second = $this->manager()->issue($user);

        $this->assertNotSame($first->familyUuid, $second->familyUuid);
        $this->assertSame(2, SanctumRefreshToken::query()->count());
        $this->assertSame(
            2,
            SanctumRefreshToken::query()->distinct()->count('family_uuid'),
        );
    }

    #[Test]
    public function a_tokenable_without_sanctums_trait_is_rejected(): void
    {
        $this->createUser();
        $plain = PlainModel::query()->firstOrFail();

        try {
            $this->manager()->issue($plain);
            $this->fail('Issuance should have been refused.');
        } catch (InvalidTokenableException $e) {
            $this->assertSame('invalid_tokenable', $e->errorCode());
        }

        $this->assertSame(0, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function the_result_carries_both_plaintext_tokens_and_their_expiries(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        $this->assertNotSame('', $pair->accessToken);
        $this->assertNotSame('', $pair->refreshToken);
        $this->assertNotNull($pair->accessTokenExpiresAt);
        $this->assertNotNull($pair->refreshTokenExpiresAt);
    }

    #[Test]
    public function the_plaintext_refresh_token_is_unrecoverable_from_storage(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        $row = SanctumRefreshToken::query()->firstOrFail();

        foreach ($row->getAttributes() as $value) {
            $this->assertNotSame($pair->refreshToken, $value);
        }

        // Nor is the secret half of it stored anywhere.
        [, $secret] = explode('|', $pair->refreshToken, 2);

        foreach ($row->getAttributes() as $value) {
            $this->assertNotSame($secret, $value);
        }
    }

    #[Test]
    public function configured_lifetimes_are_applied(): void
    {
        Carbon::setTestNow($now = Carbon::parse('2026-03-01 12:00:00'));

        config([
            'sanctum-refresh-token.expiration.access_token' => 15,
            'sanctum-refresh-token.expiration.refresh_token' => 60,
            'sanctum-refresh-token.expiration.family' => 600,
        ]);

        $pair = $this->manager()->issue($this->createUser());

        $this->assertTrue($now->copy()->addMinutes(15)->eq($pair->accessTokenExpiresAt));
        $this->assertTrue($now->copy()->addMinutes(60)->eq($pair->refreshTokenExpiresAt));
        $this->assertTrue($now->copy()->addMinutes(600)->eq($pair->familyExpiresAt));

        Carbon::setTestNow();
    }

    #[Test]
    public function a_per_issuance_override_wins_over_configuration(): void
    {
        config(['sanctum-refresh-token.expiration.refresh_token' => 60]);

        // Whole seconds: the column has no sub-second precision to compare on.
        $explicit = Carbon::now()->addDays(3)->startOfSecond();

        $pair = $this->manager()->issue(
            $this->createUser(),
            TokenConfig::make()->withRefreshTokenExpiresAt($explicit),
        );

        $this->assertTrue($explicit->eq($pair->refreshTokenExpiresAt));
        $this->assertTrue($explicit->eq(SanctumRefreshToken::query()->firstOrFail()->expires_at));
    }

    #[Test]
    public function a_null_absolute_family_lifetime_leaves_the_family_uncapped(): void
    {
        config(['sanctum-refresh-token.expiration.family' => null]);

        $this->manager()->issue($this->createUser());

        $this->assertNull(SanctumRefreshToken::query()->firstOrFail()->family_expires_at);
    }

    #[Test]
    public function abilities_are_persisted_at_issuance(): void
    {
        $this->manager()->issue(
            $this->createUser(),
            TokenConfig::make()->withAbilities(['orders:read', 'orders:write']),
        );

        $this->assertSame(
            ['orders:read', 'orders:write'],
            SanctumRefreshToken::query()->firstOrFail()->abilities,
        );
    }

    #[Test]
    public function the_default_ability_set_is_applied_when_none_is_given(): void
    {
        config(['sanctum-refresh-token.rotation.default_abilities' => ['orders:read']]);

        $this->manager()->issue($this->createUser());

        $this->assertSame(['orders:read'], SanctumRefreshToken::query()->firstOrFail()->abilities);
    }

    #[Test]
    public function exceeding_the_family_limit_revokes_the_least_recently_used_family(): void
    {
        config(['sanctum-refresh-token.rotation.max_concurrent_families' => 2]);

        $user = $this->createUser();

        Carbon::setTestNow(Carbon::parse('2026-03-01 10:00:00'));
        $oldest = $this->manager()->issue($user);

        Carbon::setTestNow(Carbon::parse('2026-03-01 11:00:00'));
        $middle = $this->manager()->issue($user);

        Carbon::setTestNow(Carbon::parse('2026-03-01 12:00:00'));
        $newest = $this->manager()->issue($user);

        Carbon::setTestNow();

        $revoked = SanctumRefreshToken::query()->where('family_uuid', $oldest->familyUuid)->firstOrFail();

        $this->assertNotNull($revoked->revoked_at);
        $this->assertSame(RevocationReason::FamilyLimit, $revoked->revocation_reason);

        foreach ([$middle, $newest] as $live) {
            $this->assertNull(
                SanctumRefreshToken::query()->where('family_uuid', $live->familyUuid)->firstOrFail()->revoked_at,
            );
        }
    }

    #[Test]
    public function a_null_limit_allows_unbounded_families(): void
    {
        config(['sanctum-refresh-token.rotation.max_concurrent_families' => null]);

        $user = $this->createUser();

        for ($i = 0; $i < 5; $i++) {
            $this->manager()->issue($user);
        }

        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());
    }

    #[Test]
    public function issuance_dispatches_an_event(): void
    {
        Event::fake([RefreshTokenIssued::class]);

        $pair = $this->manager()->issue($this->createUser());

        Event::assertDispatched(
            RefreshTokenIssued::class,
            static fn (RefreshTokenIssued $event): bool => $event->familyUuid === $pair->familyUuid
                && $event->generation === 1,
        );
    }
}
