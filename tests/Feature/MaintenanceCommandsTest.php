<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\Exceptions\RotationInProgressException;
use Reiarseni\SanctumRefreshToken\Observability\GraceReplayRecorder;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

final class MaintenanceCommandsTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    // ---------------------------------------------------------------- prune

    #[Test]
    public function prune_deletes_past_the_window_and_spares_rows_within_it(): void
    {
        config(['sanctum-refresh-token.prune.retention_days' => 7]);

        $user = $this->createUser();

        $old = $this->manager()->issue($user);
        $recent = $this->manager()->issue($user);

        $this->manager()->revokeFamily($old->familyUuid);
        $this->manager()->revokeFamily($recent->familyUuid);

        SanctumRefreshToken::query()
            ->where('family_uuid', $old->familyUuid)
            ->update(['revoked_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(0, SanctumRefreshToken::query()->where('family_uuid', $old->familyUuid)->count());
        $this->assertSame(1, SanctumRefreshToken::query()->where('family_uuid', $recent->familyUuid)->count());
    }

    #[Test]
    public function prune_never_touches_a_live_row(): void
    {
        $user = $this->createUser();
        $live = $this->manager()->issue($user);

        $this->artisan('sanctum-refresh:prune', ['--days' => '0'])->assertSuccessful();

        $this->assertSame(1, SanctumRefreshToken::query()->count());
        $this->assertSame(2, $this->manager()->rotate($live->refreshToken)->generation);
    }

    #[Test]
    public function a_prune_dry_run_reports_without_deleting(): void
    {
        $user = $this->createUser();
        $pair = $this->manager()->issue($user);

        $this->manager()->revokeFamily($pair->familyUuid);
        SanctumRefreshToken::query()->update(['revoked_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function prune_deletes_rows_whose_own_expiry_is_long_past(): void
    {
        config(['sanctum-refresh-token.expiration.refresh_token' => 60]);

        $this->manager()->issue($this->createUser());

        SanctumRefreshToken::query()->update(['expires_at' => Carbon::now()->subDays(30)]);

        $this->artisan('sanctum-refresh:prune')->assertSuccessful();

        $this->assertSame(0, SanctumRefreshToken::query()->count());
    }

    #[Test]
    #[Group('concurrency')]
    public function pruning_during_active_rotation_disturbs_nothing(): void
    {
        $user = $this->createUser();

        $rotating = $this->manager()->issue($user);
        $stale = $this->manager()->issue($user);

        $this->manager()->revokeFamily($stale->familyUuid);
        SanctumRefreshToken::query()
            ->where('family_uuid', $stale->familyUuid)
            ->update(['revoked_at' => Carbon::now()->subDays(30)]);

        // Interleaved rather than concurrent: the invariant under test is that
        // pruning terminal rows cannot reach a family that is mid-rotation, and
        // that holds regardless of scheduling.
        $second = $this->manager()->rotate($rotating->refreshToken);
        $this->artisan('sanctum-refresh:prune')->assertSuccessful();
        $third = $this->manager()->rotate($second->refreshToken);

        $this->assertSame(3, $third->generation);
        $this->assertSame(0, SanctumRefreshToken::query()->where('family_uuid', $stale->familyUuid)->count());
    }

    // --------------------------------------------------------------- doctor

    #[Test]
    public function doctor_breaks_revocations_down_by_reason(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);

        $user = $this->createUser();

        $loggedOut = $this->manager()->issue($user);
        $this->manager()->revokeFamily($loggedOut->familyUuid, RevocationReason::Logout);

        $reused = $this->manager()->issue($user);
        $this->manager()->rotate($reused->refreshToken);

        try {
            $this->manager()->rotate($reused->refreshToken);
        } catch (RefreshTokenReusedException) {
            // expected
        }

        $this->artisan('sanctum-refresh:doctor')
            ->expectsOutputToContain('reuse_detected')
            ->expectsOutputToContain('logout')
            ->assertSuccessful();
    }

    #[Test]
    public function doctor_reports_zero_over_an_empty_period_rather_than_failing(): void
    {
        $this->manager()->issue($this->createUser());

        $this->artisan('sanctum-refresh:doctor', ['--days' => 1])
            ->expectsOutputToContain('reuse_detected')
            ->assertSuccessful();
    }

    #[Test]
    public function doctor_includes_grace_replays_when_observability_is_enabled(): void
    {
        config([
            'sanctum-refresh-token.rotation.reuse_grace_period' => 60,
            'sanctum-refresh-token.observability.record_grace_replays' => true,
        ]);

        $pair = $this->manager()->issue($this->createUser());
        $this->manager()->rotate($pair->refreshToken);

        try {
            $this->manager()->rotate($pair->refreshToken);
        } catch (RotationInProgressException) {
            // expected
        }

        $this->artisan('sanctum-refresh:doctor')
            ->expectsOutputToContain('Grace-period replays')
            ->assertSuccessful();

        $this->assertSame(
            1,
            $this->app->make(GraceReplayRecorder::class)
                ->countSince(Carbon::now()->subDay()),
        );
    }

    #[Test]
    public function doctor_warns_when_grace_replays_are_not_being_recorded(): void
    {
        config(['sanctum-refresh-token.observability.record_grace_replays' => false]);

        $this->artisan('sanctum-refresh:doctor')
            ->expectsOutputToContain('not being recorded')
            ->assertSuccessful();
    }

    // --------------------------------------------------------------- import

    #[Test]
    public function an_imported_d076_token_rotates_without_a_re_login(): void
    {
        $user = $this->createUser();
        $plaintext = $this->seedD076Table($user->getKey(), $user->getMorphClass());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();

        $rotated = $this->manager()->rotate($plaintext);

        $this->assertSame(2, $rotated->generation);
    }

    #[Test]
    public function each_imported_token_becomes_its_own_family(): void
    {
        $user = $this->createUser();

        $this->seedD076Table($user->getKey(), $user->getMorphClass());
        $this->seedD076Table($user->getKey(), $user->getMorphClass());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();

        $rows = SanctumRefreshToken::query()->get();

        $this->assertCount(2, $rows);
        $this->assertCount(2, $rows->pluck('family_uuid')->unique());
        $this->assertSame([1, 1], $rows->pluck('generation')->all());
    }

    #[Test]
    public function expired_source_rows_are_skipped(): void
    {
        $user = $this->createUser();

        $this->seedD076Table($user->getKey(), $user->getMorphClass());
        $this->seedD076Table($user->getKey(), $user->getMorphClass(), Carbon::now()->subDay());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();

        $this->assertSame(1, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function an_import_dry_run_writes_nothing(): void
    {
        $user = $this->createUser();
        $this->seedD076Table($user->getKey(), $user->getMorphClass());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076', '--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(0, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function importing_twice_does_not_duplicate_families(): void
    {
        $user = $this->createUser();
        $this->seedD076Table($user->getKey(), $user->getMorphClass());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();
        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();

        $this->assertSame(1, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function issuance_still_works_after_an_import(): void
    {
        $user = $this->createUser();
        $this->seedD076Table($user->getKey(), $user->getMorphClass());

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])->assertSuccessful();

        // Imported rows carry explicit ids, so the next auto-incremented id
        // has to land clear of them. On PostgreSQL that needs the sequence
        // realigned; getting it wrong makes the first login after a migration
        // collide with an imported row.
        $pair = $this->manager()->issue($user);

        $this->assertSame(1, $pair->generation);
        $this->assertSame(2, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function an_unrecognised_source_schema_fails_safely(): void
    {
        Schema::create('personal_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('something_else');
        });

        $this->artisan('sanctum-refresh:import', ['source' => 'd076'])
            ->expectsOutputToContain('missing column')
            ->assertFailed();

        $this->assertSame(0, SanctumRefreshToken::query()->count());
    }

    #[Test]
    public function an_unknown_source_is_refused(): void
    {
        $this->artisan('sanctum-refresh:import', ['source' => 'nope'])
            ->expectsOutputToContain('Unknown source')
            ->assertFailed();
    }

    #[Test]
    public function importing_from_this_packages_own_table_is_refused(): void
    {
        $this->artisan('sanctum-refresh:import', ['source' => 'albetnov'])
            ->expectsOutputToContain("package's own")
            ->assertFailed();
    }

    /**
     * Create D076's table if needed and seed one live row, returning the
     * plaintext its holder would be carrying.
     */
    private function seedD076Table(mixed $tokenableId, string $tokenableType, ?Carbon $expiresAt = null): string
    {
        if (! Schema::hasTable('personal_refresh_tokens')) {
            Schema::create('personal_refresh_tokens', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('access_token_id')->nullable();
                $table->morphs('tokenable');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        $secret = bin2hex(random_bytes(20));

        $id = $this->app->make('db')->table('personal_refresh_tokens')->insertGetId([
            'access_token_id' => null,
            'tokenable_type' => $tokenableType,
            'tokenable_id' => $tokenableId,
            'token' => hash('sha256', $secret),
            'abilities' => json_encode(['*']),
            'expires_at' => $expiresAt ?? Carbon::now()->addDays(14),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $id.'|'.$secret;
    }
}
