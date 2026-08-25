<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReplayedInGracePeriod;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenRotated;
use Reiarseni\SanctumRefreshToken\Exceptions\AbilitiesEscalationException;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RotationInProgressException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * What a replay inside the grace window produces.
 *
 * `reject` is the default and the strict answer. `reissue` is the escape hatch
 * for a client that cannot be fixed, and it is what every large identity
 * provider does — at the cost Ory states plainly: inside the window, the same
 * token can be redeemed repeatedly without tripping reuse detection.
 */
final class GraceReplayModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 60]);
    }

    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function the_default_refuses_and_leaves_every_row_live(): void
    {
        $first = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($first->refreshToken);

        try {
            $this->manager()->rotate($first->refreshToken);
            $this->fail('The replay should have been refused.');
        } catch (RotationInProgressException $e) {
            $this->assertSame('rotation_in_progress', $e->errorCode());
            $this->assertSame($first->familyUuid, $e->familyUuid);
        }

        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());
        $this->assertSame(2, SanctumRefreshToken::query()->max('generation'));
    }

    #[Test]
    public function an_unrecognised_mode_falls_back_to_refusing(): void
    {
        // A typo in a config file must not silently weaken the package.
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissu']);

        $first = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($first->refreshToken);

        $this->expectException(RotationInProgressException::class);

        $this->manager()->rotate($first->refreshToken);
    }

    #[Test]
    public function the_tolerant_mode_reissues_and_advances_the_family(): void
    {
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissue']);

        $first = $this->manager()->issue($this->createUser());
        $second = $this->manager()->rotate($first->refreshToken);

        // The same consumed token again — and this time it works.
        $third = $this->manager()->rotate($first->refreshToken);

        $this->assertSame($first->familyUuid, $third->familyUuid);
        $this->assertSame(3, $third->generation);

        // And what it returned is usable, not a token-shaped consolation.
        $this->assertSame(4, $this->manager()->rotate($third->refreshToken)->generation);

        $this->assertNotSame($second->refreshToken, $third->refreshToken);
        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());
    }

    #[Test]
    public function a_reissued_replay_never_forks_the_family(): void
    {
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissue']);

        $first = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($first->refreshToken);

        // Three replays of the same consumed token, as a client with no mutex
        // would produce. Each advances the family; none of them mints a second
        // row at a generation the family already holds.
        for ($i = 0; $i < 3; $i++) {
            $this->manager()->rotate($first->refreshToken);
        }

        $generations = SanctumRefreshToken::query()
            ->where('family_uuid', $first->familyUuid)
            ->pluck('generation')
            ->all();

        $this->assertSame(
            count($generations),
            count(array_unique($generations)),
            'Reissuing from the replayed row rather than from the live one would '
            .'put two live tokens at the same generation -- the fork this package exists to prevent.',
        );

        $this->assertSame(
            1,
            SanctumRefreshToken::query()->where('family_uuid', $first->familyUuid)->live()->count(),
            'A family may hold exactly one rotatable row.',
        );
    }

    #[Test]
    public function a_reissued_replay_dispatches_both_events(): void
    {
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissue']);

        // Faked before the manager is resolved: it takes the dispatcher by
        // constructor injection and is a singleton, so a fake installed
        // afterwards would never see its events.
        Event::fake([RefreshTokenReplayedInGracePeriod::class, RefreshTokenRotated::class]);

        $first = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($first->refreshToken);
        $this->manager()->rotate($first->refreshToken);

        // The grace event is what `doctor` counts. Under this mode the client
        // sees no error, so the report is the only place a racing client is
        // still visible — losing it would switch off the diagnostic that
        // explains why the mode was turned on.
        Event::assertDispatched(
            RefreshTokenReplayedInGracePeriod::class,
            static fn (RefreshTokenReplayedInGracePeriod $e): bool => $e->familyUuid === $first->familyUuid,
        );

        Event::assertDispatched(
            RefreshTokenRotated::class,
            static fn (RefreshTokenRotated $e): bool => $e->familyUuid === $first->familyUuid
                && $e->generation === 3,
        );
    }

    #[Test]
    public function the_tolerant_mode_does_not_extend_past_the_window(): void
    {
        config([
            'sanctum-refresh-token.rotation.on_grace_replay' => 'reissue',
            'sanctum-refresh-token.rotation.reuse_grace_period' => 10,
        ]);

        $first = $this->manager()->issue($this->createUser());

        Carbon::setTestNow($start = Carbon::now());
        $this->manager()->rotate($first->refreshToken);

        Carbon::setTestNow($start->copy()->addSeconds(30));

        try {
            $this->manager()->rotate($first->refreshToken);
            $this->fail('A replay past the window should be reuse.');
        } catch (RefreshTokenReusedException $e) {
            $this->assertSame('refresh_token_reused', $e->errorCode());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            0,
            SanctumRefreshToken::query()->whereNull('revoked_at')->count(),
            'Past the window the family dies under either mode.',
        );
    }

    #[Test]
    public function a_zero_grace_period_is_strict_under_both_modes(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);

        foreach (['reject', 'reissue'] as $mode) {
            config(['sanctum-refresh-token.rotation.on_grace_replay' => $mode]);

            $user = $this->createUser("{$mode}@example.com");
            $first = $this->manager()->issue($user);
            $this->manager()->rotate($first->refreshToken);

            try {
                $this->manager()->rotate($first->refreshToken);
                $this->fail("A zero window should be strict under [{$mode}].");
            } catch (RefreshTokenReusedException $e) {
                $this->assertSame('refresh_token_reused', $e->errorCode());
            }

            $this->assertSame(
                0,
                SanctumRefreshToken::query()
                    ->where('family_uuid', $first->familyUuid)
                    ->whereNull('revoked_at')
                    ->count(),
                "The family should be revoked under [{$mode}].",
            );
        }
    }

    #[Test]
    public function a_reissued_replay_still_refuses_to_widen_abilities(): void
    {
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissue']);

        $first = $this->manager()->issue($this->createUser(), abilities: ['orders:read']);
        $this->manager()->rotate($first->refreshToken);

        // The tolerant mode makes a replay succeed. It must not also make it a
        // privilege-escalation primitive.
        $this->expectException(AbilitiesEscalationException::class);

        $this->manager()->rotate($first->refreshToken, ['orders:read', 'admin']);
    }
}
