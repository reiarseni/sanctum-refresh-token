<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Support\Identifier;
use stdClass;

/**
 * Converts another package's refresh tokens into families without logging
 * anybody out — the cost that otherwise keeps people on a package they know is
 * weak.
 *
 * It works because both sources hash with `hash('sha256', $secret)` and hand
 * out `<id>|<secret>`, exactly as this package does. What differs is which id
 * they embed: D076 the refresh row's own, albetnov the access token's. Each row
 * is therefore inserted under precisely the id its own plaintext embeds, which
 * is what makes a token the user already holds resolve here.
 */
class ImportCommand extends Command
{
    protected $signature = 'sanctum-refresh:import
                            {source : The package to import from: albetnov or d076}
                            {--table= : Override the source table name}
                            {--dry-run : Report what would be imported without writing}';

    protected $description = 'Import live refresh tokens from albetnov/sanctum-refresh or D076/sanctum-refresh-tokens';

    /**
     * The columns each supported source is recognised by.
     *
     * @var array<string, array{table: string, columns: list<string>}>
     */
    private const SOURCES = [
        'albetnov' => [
            'table' => 'refresh_tokens',
            'columns' => ['id', 'token_id', 'token', 'expires_at'],
        ],
        'd076' => [
            'table' => 'personal_refresh_tokens',
            'columns' => ['id', 'access_token_id', 'tokenable_type', 'tokenable_id', 'token', 'expires_at'],
        ],
    ];

    public function handle(): int
    {
        $argument = $this->argument('source');
        $source = is_string($argument) ? $argument : '';

        if (! array_key_exists($source, self::SOURCES)) {
            $this->components->error(sprintf(
                'Unknown source [%s]; supported sources are: %s.',
                $source,
                implode(', ', array_keys(self::SOURCES)),
            ));

            return self::FAILURE;
        }

        $table = $this->sourceTable($source);

        // albetnov's table is also called `refresh_tokens`; reading and writing
        // one table is nonsense, so it is refused rather than half-attempted.
        if ($table === SanctumRefreshToken::newRefreshToken()->getTable()) {
            $this->components->error(sprintf(
                'The source table [%s] is this package\'s own. Set '
                .'sanctum-refresh-token.table to a different name, or pass --table.',
                $table,
            ));

            return self::FAILURE;
        }

        if (! Schema::hasTable($table)) {
            $this->components->error(sprintf('The source table [%s] does not exist.', $table));

            return self::FAILURE;
        }

        // A half-matching schema is refused outright: guessing would write rows
        // that silently authenticate nobody.
        $missing = $this->missingColumns($table, self::SOURCES[$source]['columns']);

        if ($missing !== []) {
            $this->components->error(sprintf(
                'The table [%s] does not match the %s schema; missing column(s): %s. Nothing was written.',
                $table,
                $source,
                implode(', ', $missing),
            ));

            return self::FAILURE;
        }

        return $this->import($source, $table);
    }

    private function import(string $source, string $table): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $imported = 0;
        $skippedExpired = 0;
        $skippedDuplicate = 0;
        $skippedOrphan = 0;

        $target = SanctumRefreshToken::newRefreshToken();
        $accessTokens = Sanctum::personalAccessTokenModel();

        foreach ($this->connection()->table($table)->orderBy('id')->cursor() as $row) {
            /** @var stdClass $row */
            $expiresAt = is_scalar($row->expires_at) ? Carbon::parse((string) $row->expires_at) : null;

            if ($expiresAt !== null && $expiresAt->isPast()) {
                $skippedExpired++;

                continue;
            }

            if (property_exists($row, 'revoked_at') && $row->revoked_at !== null) {
                $skippedExpired++;

                continue;
            }

            $identity = $this->identity($source, $row, $accessTokens);

            if ($identity === null) {
                $skippedOrphan++;

                continue;
            }

            [$targetId, $tokenableType, $tokenableId, $accessTokenId, $name, $abilities] = $identity;

            // Idempotency: a second run finds the row it wrote on the first and
            // leaves it alone rather than opening a second family for it.
            if ($target->newQuery()->whereKey($targetId)->exists()) {
                $skippedDuplicate++;

                continue;
            }

            $imported++;

            if ($dryRun) {
                continue;
            }

            $target->newQuery()->insert([
                'id' => $targetId,
                'family_uuid' => (string) Str::uuid(),
                'tokenable_type' => $tokenableType,
                'tokenable_id' => $tokenableId,
                'access_token_id' => $accessTokenId,
                'name' => $name,
                'token' => (string) $row->token,
                'abilities' => json_encode($abilities),
                'previous_id' => null,
                'generation' => 1,
                'ip_hash' => null,
                'user_agent_hash' => null,
                'expires_at' => $expiresAt,
                'family_expires_at' => null,
                'rotated_at' => null,
                'revoked_at' => null,
                'revocation_reason' => null,
                'last_used_at' => $now,
                'created_at' => $row->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        if (! $dryRun && $imported > 0) {
            $this->realignIdentitySequence($target->getTable());
        }

        $this->report($dryRun, $imported, $skippedExpired, $skippedDuplicate, $skippedOrphan);

        return self::SUCCESS;
    }

    /**
     * Imported rows carry explicit ids. MySQL bumps AUTO_INCREMENT to match one;
     * PostgreSQL leaves its identity sequence where it was, so without this the
     * next issuance would try id 1 and collide with an imported row.
     */
    private function realignIdentitySequence(string $table): void
    {
        $connection = $this->connection();

        if (! $connection instanceof Connection || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        // pg_get_serial_sequence resolves the sequence behind the column, so
        // this holds whatever the table is named.
        $connection->statement(
            'SELECT setval(pg_get_serial_sequence(?, ?), COALESCE((SELECT MAX(id) FROM '
            .$connection->getQueryGrammar()->wrapTable($table).'), 0) + 1, false)',
            [$table, 'id'],
        );
    }

    /**
     * Null when the row cannot be attributed to a tokenable, which for albetnov
     * means its access token is already gone.
     *
     * @param  class-string<Model>  $accessTokens
     * @return array{0: int, 1: string, 2: int|string, 3: int|null, 4: string, 5: list<string>}|null
     */
    private function identity(string $source, stdClass $row, string $accessTokens): ?array
    {
        if ($source === 'd076') {
            $abilities = property_exists($row, 'abilities') && is_string($row->abilities)
                ? (array) json_decode($row->abilities, true)
                : ['*'];

            return [
                // D076 embeds the refresh row's own id in the plaintext.
                (int) $row->id,
                (string) $row->tokenable_type,
                $row->tokenable_id,
                $row->access_token_id === null ? null : (int) $row->access_token_id,
                'Imported session',
                self::stringList($abilities),
            ];
        }

        // albetnov embeds the access token's id, and keeps the tokenable only
        // on the access token, so an orphaned refresh row cannot be imported.
        $accessToken = $accessTokens::query()->whereKey($row->token_id)->first();

        if ($accessToken === null) {
            return null;
        }

        /** @var object{tokenable_type: string, tokenable_id: int|string, name: string, abilities: mixed} $accessToken */
        $abilities = is_array($accessToken->abilities) ? $accessToken->abilities : ['*'];

        return [
            (int) $row->token_id,
            $accessToken->tokenable_type,
            $accessToken->tokenable_id,
            (int) $row->token_id,
            $accessToken->name,
            self::stringList($abilities),
        ];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_scalar($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings === [] ? ['*'] : $strings;
    }

    private function report(bool $dryRun, int $imported, int $expired, int $duplicate, int $orphan): void
    {
        $verb = $dryRun ? 'would be imported' : 'imported';

        $this->components->info(sprintf('%d token(s) %s as single-generation families.', $imported, $verb));
        $this->components->twoColumnDetail('Skipped, expired or revoked', (string) $expired);
        $this->components->twoColumnDetail('Skipped, already imported', (string) $duplicate);
        $this->components->twoColumnDetail('Skipped, no tokenable', (string) $orphan);

        if ($dryRun) {
            $this->components->warn('Dry run: nothing was written.');
        }
    }

    /**
     * @param  list<string>  $required
     * @return list<string>
     */
    private function missingColumns(string $table, array $required): array
    {
        return array_values(array_filter(
            $required,
            static fn (string $column): bool => ! Schema::hasColumn($table, $column),
        ));
    }

    private function sourceTable(string $source): string
    {
        $override = $this->option('table');

        $table = is_string($override) && $override !== ''
            ? $override
            : self::SOURCES[$source]['table'];

        return Identifier::assertSafe($table, 'source table');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(SanctumRefreshToken::newRefreshToken()->getConnectionName());
    }
}
