<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Exceptions\RefreshTokenReusedException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * The published schema, exercised against whatever engine is running.
 *
 * SQLite accepts a great deal that MySQL and PostgreSQL reject — column types,
 * index name collisions, string lengths in unique keys. This group therefore
 * runs against both server engines in the integration CI tier, where the
 * migration either applies cleanly or it does not.
 */
#[Group('migrations')]
final class MigrationsTest extends TestCase
{
    #[Test]
    public function the_published_schema_carries_every_column_the_package_writes(): void
    {
        $table = SanctumRefreshToken::newRefreshToken()->getTable();

        $this->assertTrue(Schema::hasTable($table));

        $expected = [
            'id',
            'family_uuid',
            'tokenable_type',
            'tokenable_id',
            'access_token_id',
            'name',
            'token',
            'abilities',
            'previous_id',
            'generation',
            'ip_hash',
            'user_agent_hash',
            'expires_at',
            'family_expires_at',
            'rotated_at',
            'revoked_at',
            'revocation_reason',
            'last_used_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "The published schema is missing the [{$column}] column.",
            );
        }
    }

    #[Test]
    public function the_token_column_is_unique(): void
    {
        $table = SanctumRefreshToken::newRefreshToken()->getTable();

        // Read from the schema rather than provoking a duplicate insert.
        // Forcing the constraint to fire is not portable: PostgreSQL aborts the
        // surrounding transaction on any error, and MySQL invalidates the
        // savepoint that would otherwise contain it, so the test would poison
        // whatever ran after it on one engine or the other.
        $unique = collect(Schema::getIndexes($table))
            ->filter(static fn (array $index): bool => (bool) ($index['unique'] ?? false))
            ->flatMap(static fn (array $index): array => $index['columns'] ?? [])
            ->all();

        $this->assertContains(
            'token',
            $unique,
            'The token hash must be unique at the storage layer, not merely improbable in the application.',
        );
    }

    #[Test]
    public function the_context_migration_applies_and_is_written_to(): void
    {
        $table = SanctumRefreshToken::newRefreshToken()->getTable();

        $this->assertFalse(
            Schema::hasColumn($table, 'context'),
            'The context column ships separately and must not be created by the main migration.',
        );

        $this->addContextColumn();

        $this->assertTrue(Schema::hasColumn($table, 'context'));

        config([
            'sanctum-refresh-token.context.enabled' => true,
            'sanctum-refresh-token.context.column' => 'context',
        ]);

        SanctumRefreshToken::resolveContextUsing(static fn (): string => 'ACME');

        $this->manager()->issue($this->createUser());

        $this->assertSame('ACME', SanctumRefreshToken::query()->firstOrFail()->getAttribute('context'));
    }

    #[Test]
    public function a_full_family_lifecycle_round_trips_through_the_schema(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);

        $user = $this->createUser();
        $manager = $this->manager();

        $first = $manager->issue($user);
        $second = $manager->rotate($first->refreshToken);
        $third = $manager->rotate($second->refreshToken);

        $this->assertSame(3, $third->generation);

        try {
            $manager->rotate($first->refreshToken);
            $this->fail('The replay should have been refused.');
        } catch (RefreshTokenReusedException) {
            // expected
        }

        // Every column the lifecycle writes has to survive a round trip on the
        // engine under test: timestamps, the enum-backed reason, the JSON
        // abilities, the nullable lineage pointer.
        $rows = SanctumRefreshToken::query()->orderBy('generation')->get();

        $this->assertCount(3, $rows);
        $this->assertNull($rows[0]->previous_id);
        $this->assertSame($rows[0]->getKey(), $rows[1]->previous_id);
        $this->assertSame(['*'], $rows[2]->abilities);

        foreach ($rows as $row) {
            $this->assertNotNull($row->revoked_at);
            $this->assertSame('reuse_detected', $row->revocation_reason?->value);
        }
    }

    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }
}
