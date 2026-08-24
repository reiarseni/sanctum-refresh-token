<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Enums\ReuseStrategy;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReuseDetected;
use Reiarseni\SanctumRefreshToken\Events\TokenFamilyRevoked;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenRevokedException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenPair;

/**
 * The behaviour this package exists for.
 *
 * Every test here runs with the grace window at zero, so that a replay is
 * unambiguously a replay: the window's own behaviour is covered in RotationTest.
 */
final class ReuseDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);
    }

    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function a_replay_outside_the_grace_window_kills_the_whole_family(): void
    {
        $first = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($first->refreshToken);

        try {
            $this->manager()->rotate($first->refreshToken);
            $this->fail('The replay should have been refused.');
        } catch (RefreshTokenReusedException $e) {
            $this->assertSame('refresh_token_reused', $e->errorCode());
            $this->assertSame($first->familyUuid, $e->familyUuid);
            $this->assertSame(1, $e->replayedGeneration);
            $this->assertSame(2, $e->currentGeneration);
        }

        $rows = SanctumRefreshToken::query()->where('family_uuid', $first->familyUuid)->get();

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertNotNull($row->revoked_at);
            $this->assertSame(RevocationReason::ReuseDetected, $row->revocation_reason);
        }
    }

    #[Test]
    public function the_legitimate_clients_current_token_is_revoked_too(): void
    {
        $user = $this->createUser();

        // The legitimate holder is at generation 2; the attacker replays 1.
        $stolen = $this->manager()->issue($user);
        $legitimate = $this->manager()->rotate($stolen->refreshToken);

        try {
            $this->manager()->rotate($stolen->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        try {
            $this->manager()->rotate($legitimate->refreshToken);
            $this->fail("The legitimate holder's next rotation should have been refused.");
        } catch (RefreshTokenRevokedException $e) {
            $this->assertSame('refresh_token_revoked', $e->errorCode());
            $this->assertSame(RevocationReason::ReuseDetected, $e->reason);
        }
    }

    #[Test]
    public function every_access_token_of_the_family_stops_authenticating(): void
    {
        $user = $this->createUser();

        $first = $this->manager()->issue($user);
        $accessTokenIds = [SanctumRefreshToken::query()->firstOrFail()->access_token_id];

        $this->manager()->rotate($first->refreshToken);
        $accessTokenIds[] = SanctumRefreshToken::query()->where('generation', 2)->firstOrFail()->access_token_id;

        try {
            $this->manager()->rotate($first->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        foreach ($accessTokenIds as $id) {
            $this->assertNull(Sanctum::personalAccessTokenModel()::query()->find($id));
        }
    }

    #[Test]
    public function other_families_of_the_same_tokenable_survive(): void
    {
        $user = $this->createUser();

        $compromised = $this->manager()->issue($user);
        $untouched = $this->manager()->issue($user);

        $this->manager()->rotate($compromised->refreshToken);

        try {
            $this->manager()->rotate($compromised->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        $survivor = $this->manager()->rotate($untouched->refreshToken);

        $this->assertSame($untouched->familyUuid, $survivor->familyUuid);
        $this->assertSame(2, $survivor->generation);
    }

    #[Test]
    public function detection_dispatches_a_dedicated_event(): void
    {
        Event::fake([RefreshTokenReuseDetected::class, TokenFamilyRevoked::class]);

        $user = $this->createUser();
        $first = $this->manager()->issue($user);
        $this->manager()->rotate($first->refreshToken);

        try {
            $this->manager()->rotate($first->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        Event::assertDispatched(
            RefreshTokenReuseDetected::class,
            function (RefreshTokenReuseDetected $event) use ($first, $user): bool {
                $this->assertSame($first->familyUuid, $event->familyUuid);
                $this->assertSame(1, $event->replayedGeneration);
                $this->assertSame(2, $event->currentGeneration);
                $this->assertTrue($event->tokenable->is($user));

                foreach (get_object_vars($event) as $value) {
                    $this->assertNotSame($first->refreshToken, $value);
                }

                return true;
            },
        );

        Event::assertDispatched(
            TokenFamilyRevoked::class,
            static fn (TokenFamilyRevoked $event): bool => $event->familyUuid === $first->familyUuid
                && $event->reason === RevocationReason::ReuseDetected,
        );
    }

    #[Test]
    public function the_token_only_strategy_leaves_the_family_live(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_strategy' => ReuseStrategy::RevokeToken]);

        Event::fake([RefreshTokenReuseDetected::class]);

        $first = $this->replayOnce();

        $replayed = SanctumRefreshToken::query()->where('generation', 1)->firstOrFail();
        $current = SanctumRefreshToken::query()->where('generation', 2)->firstOrFail();

        $this->assertNotNull($replayed->revoked_at);
        $this->assertNull($current->revoked_at);

        Event::assertDispatched(RefreshTokenReuseDetected::class);

        // The legitimate holder can still rotate.
        $this->assertSame(3, $this->manager()->rotate($first['live']->refreshToken)->generation);
    }

    #[Test]
    public function the_observe_only_strategy_revokes_nothing(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_strategy' => ReuseStrategy::Observe]);

        Event::fake([RefreshTokenReuseDetected::class]);

        $this->replayOnce();

        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());

        Event::assertDispatched(RefreshTokenReuseDetected::class);
    }

    #[Test]
    public function each_revocation_path_records_its_own_reason(): void
    {
        config(['sanctum-refresh-token.rotation.max_concurrent_families' => 1]);

        $user = $this->createUser();

        $limited = $this->manager()->issue($user);
        $this->manager()->issue($user);

        $this->assertSame(
            RevocationReason::FamilyLimit,
            SanctumRefreshToken::query()->where('family_uuid', $limited->familyUuid)->firstOrFail()->revocation_reason,
        );

        config(['sanctum-refresh-token.rotation.max_concurrent_families' => null]);

        $loggedOut = $this->manager()->issue($user);
        $this->manager()->revokeFamily($loggedOut->familyUuid, RevocationReason::Logout);

        $this->assertSame(
            RevocationReason::Logout,
            SanctumRefreshToken::query()->where('family_uuid', $loggedOut->familyUuid)->firstOrFail()->revocation_reason,
        );

        $reused = $this->manager()->issue($user);
        $this->manager()->rotate($reused->refreshToken);

        try {
            $this->manager()->rotate($reused->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        $this->assertSame(
            RevocationReason::ReuseDetected,
            SanctumRefreshToken::query()->where('family_uuid', $reused->familyUuid)->firstOrFail()->revocation_reason,
        );
    }

    #[Test]
    public function the_revocation_reason_set_is_closed(): void
    {
        $pair = $this->manager()->issue($this->createUser());
        $this->manager()->revokeFamily($pair->familyUuid, RevocationReason::Logout);

        $persisted = SanctumRefreshToken::query()->firstOrFail()->getRawOriginal('revocation_reason');

        $this->assertContains(
            $persisted,
            array_column(RevocationReason::cases(), 'value'),
        );
        $this->assertInstanceOf(
            RevocationReason::class,
            SanctumRefreshToken::query()->firstOrFail()->revocation_reason,
        );
    }

    /**
     * Issue, rotate once, then replay the consumed token.
     *
     * @return array{stolen: TokenPair, live: TokenPair}
     */
    private function replayOnce(): array
    {
        $stolen = $this->manager()->issue($this->createUser());
        $live = $this->manager()->rotate($stolen->refreshToken);

        try {
            $this->manager()->rotate($stolen->refreshToken);
            $this->fail('The replay should have been refused.');
        } catch (RefreshTokenReusedException $e) {
            $this->assertSame('refresh_token_reused', $e->errorCode());
        }

        return ['stolen' => $stolen, 'live' => $live];
    }
}
