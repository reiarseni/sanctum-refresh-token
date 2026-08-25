<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\SanctumRefreshTokenServiceProvider;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * What stops the table growing forever.
 *
 * A package that keeps consumed rows on purpose owes an answer to "how big does
 * this get, and what happens after three years". These are that answer.
 */
final class StorageLifecycleTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function a_terminal_row_carrying_no_expiry_is_still_prunable(): void
    {
        // No token expiry, but the family is capped, so this configuration is
        // allowed -- and its rotated rows carry neither revoked_at nor
        // expires_at. Age is the only thing left to prune them by.
        config([
            'sanctum-refresh-token.expiration.refresh_token' => null,
            'sanctum-refresh-token.expiration.family' => 60 * 24 * 30,
            'sanctum-refresh-token.prune.retention_days' => 7,
        ]);

        // Rotated twice, so generation 2 is terminal and is not the anchor.
        $pair = $this->manager()->issue($this->createUser());
        $pair = $this->manager()->rotate($pair->refreshToken);
        $this->manager()->rotate($pair->refreshToken);

        $consumed = SanctumRefreshToken::query()->where('generation', 2)->firstOrFail();

        $this->assertNull($consumed->expires_at);
        $this->assertNull($consumed->revoked_at);

        SanctumRefreshToken::query()->update(['created_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(
            0,
            SanctumRefreshToken::query()->where('generation', 2)->count(),
            'A rotated row with no expiry must be reachable by its age.',
        );
    }

    #[Test]
    public function a_terminal_row_without_expiry_inside_the_window_survives(): void
    {
        config([
            'sanctum-refresh-token.expiration.refresh_token' => null,
            'sanctum-refresh-token.expiration.family' => 60 * 24 * 30,
            'sanctum-refresh-token.prune.retention_days' => 7,
        ]);

        $pair = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($pair->refreshToken);

        SanctumRefreshToken::query()->update(['created_at' => Carbon::now()->subDays(2)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(2, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function the_anchor_of_a_live_family_survives_pruning(): void
    {
        config([
            'sanctum-refresh-token.expiration.refresh_token' => 60 * 24 * 14,
            'sanctum-refresh-token.prune.retention_days' => 7,
        ]);

        $pair = $this->manager()->issue($this->createUser());
        $pair = $this->manager()->rotate($pair->refreshToken);

        // Generation 1 is long expired; the family is still very much alive.
        SanctumRefreshToken::query()
            ->where('generation', 1)
            ->update(['expires_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(
            1,
            SanctumRefreshToken::query()->where('generation', 1)->count(),
            'The anchor carries the lock every rotation takes; deleting it under a live family '
            .'would leave its generations with nothing to serialise against.',
        );

        // And the family still rotates.
        $this->assertSame(3, $this->manager()->rotate($pair->refreshToken)->generation);
    }

    #[Test]
    public function the_anchor_is_pruned_once_the_family_is_dead(): void
    {
        config(['sanctum-refresh-token.prune.retention_days' => 7]);

        $pair = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($pair->refreshToken);
        $this->manager()->revokeFamily($pair->familyUuid);

        SanctumRefreshToken::query()->update(['revoked_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(0, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function no_live_row_is_ever_pruned_whatever_its_age(): void
    {
        config(['sanctum-refresh-token.prune.retention_days' => 0]);

        $pair = $this->manager()->issue($this->createUser());

        SanctumRefreshToken::query()->update(['created_at' => Carbon::now()->subYears(3)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(1, SanctumRefreshToken::query()->count());
        $this->assertSame(2, $this->manager()->rotate($pair->refreshToken)->generation);
    }

    #[Test]
    public function both_lifetimes_null_is_refused_at_boot(): void
    {
        $original = [
            'sanctum-refresh-token.expiration.refresh_token' => config('sanctum-refresh-token.expiration.refresh_token'),
            'sanctum-refresh-token.expiration.family' => config('sanctum-refresh-token.expiration.family'),
        ];

        config([
            'sanctum-refresh-token.expiration.refresh_token' => null,
            'sanctum-refresh-token.expiration.family' => null,
        ]);

        try {
            (new SanctumRefreshTokenServiceProvider($this->app))->boot();
            $this->fail('The provider should have refused to boot.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('refresh_token', $e->getMessage());
            $this->assertStringContainsString('family', $e->getMessage());
        } finally {
            config($original);
        }
    }

    #[Test]
    public function an_uncapped_token_inside_a_capped_family_boots_and_issues(): void
    {
        config([
            'sanctum-refresh-token.expiration.refresh_token' => null,
            'sanctum-refresh-token.expiration.family' => 60 * 24 * 30,
        ]);

        (new SanctumRefreshTokenServiceProvider($this->app))->boot();

        $pair = $this->manager()->issue($this->createUser());

        $this->assertNull($pair->refreshTokenExpiresAt);
        $this->assertNotNull($pair->familyExpiresAt);
    }

    #[Test]
    public function the_scheduler_is_untouched_by_default(): void
    {
        config(['sanctum-refresh-token.prune.schedule' => false]);

        $this->assertSame([], $this->scheduledCommands());
    }

    #[Test]
    public function a_configured_frequency_registers_the_prune_command(): void
    {
        config(['sanctum-refresh-token.prune.schedule' => 'daily']);

        (new SanctumRefreshTokenServiceProvider($this->app))->boot();

        $this->assertStringContainsString(
            'sanctum-refresh:prune',
            implode(' ', $this->scheduledCommands()),
        );
    }

    /**
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        return array_map(
            static fn ($event): string => (string) $event->command,
            $schedule->events(),
        );
    }
}
