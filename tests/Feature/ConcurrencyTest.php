<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenPair;

/**
 * The invariants the row lock exists to hold.
 *
 * These run against MySQL and PostgreSQL in the integration CI tier. On SQLite
 * they skip with an explicit reason: SQLite has no real `SELECT ... FOR UPDATE`,
 * so passing there would prove nothing — and a test that proves nothing while
 * reporting success is worse than no test at all.
 *
 * Each case forks real processes, because two rotations interleaving inside one
 * PHP process would share a connection and therefore never contend.
 */
#[Group('concurrency')]
final class ConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        // Forked children open their own connections, so the data they rotate
        // has to be committed rather than held in this process's transaction.
        $this->needsCommittedData = true;

        parent::setUp();

        if (! $this->supportsRowLocking()) {
            $this->markTestSkipped(
                'Row-level locking is not supported on the ['
                .$this->app->make('db')->connection()->getDriverName()
                .'] connection; these invariants can only be proven on MySQL or PostgreSQL.',
            );
        }

        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 0]);
    }

    protected function tearDown(): void
    {
        // Nothing rolls back for these, so they tidy up after themselves.
        if ($this->needsCommittedData) {
            $this->truncateTokenTables();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_rotations_of_the_same_token_do_not_fork_the_family(): void
    {
        $pair = $this->app->make(RefreshTokenManager::class)->issue($this->createUser());

        $outcomes = $this->raceOn($pair, 2);

        $succeeded = count(array_filter($outcomes, static fn (string $o): bool => $o === 'ok'));

        $this->assertSame(1, $succeeded, 'Exactly one of the two rotations should have succeeded.');

        $this->assertSame(
            2,
            SanctumRefreshToken::query()->where('family_uuid', $pair->familyUuid)->count(),
            'The family should hold exactly two rows: the consumed one and its single successor.',
        );

        $this->assertSame(
            1,
            SanctumRefreshToken::query()
                ->where('family_uuid', $pair->familyUuid)
                ->where('generation', 2)
                ->count(),
            'The family must never hold two live rows at the same generation.',
        );
    }

    #[Test]
    public function a_rotation_racing_a_replay_resolves_to_exactly_one_outcome(): void
    {
        $manager = $this->app->make(RefreshTokenManager::class);

        $first = $manager->issue($this->createUser());
        $live = $manager->rotate($first->refreshToken);

        // One process replays the consumed generation while another rotates the
        // live one. The family is either advanced or revoked, never both.
        $outcomes = $this->raceWith([
            fn (): string => $this->attempt($first->refreshToken),
            fn (): string => $this->attempt($live->refreshToken),
        ]);

        $rows = SanctumRefreshToken::query()->where('family_uuid', $first->familyUuid)->get();

        // The replay of a consumed generation is always reuse here -- the grace
        // window is zero -- so the family always ends up dead. What the race
        // decides is only whether the legitimate rotation got its new
        // generation in first, and that generation must die with the rest.
        $this->assertSame(
            $rows->count(),
            $rows->whereNotNull('revoked_at')->count(),
            'A detected reuse must revoke the family as a unit, not partially: '
            .'a generation created while the revocation was running must not survive it.',
        );

        $this->assertContains(
            'refresh_token_reused',
            $outcomes,
            'The replayed generation should have been recognised as reuse.',
        );

        // The other process either won the race or found the family already
        // dead. Both are coherent; two successes, or a success alongside a
        // surviving row, would not be.
        $this->assertCount(2, $outcomes);

        foreach ($outcomes as $outcome) {
            $this->assertContains(
                $outcome,
                ['ok', 'refresh_token_reused', 'refresh_token_revoked'],
                "Unexpected outcome [{$outcome}] from a rotation racing a replay.",
            );
        }
    }

    /**
     * Run one rotation of the same token in N forked processes.
     *
     * @return list<string>
     */
    private function raceOn(TokenPair $pair, int $processes): array
    {
        $callables = [];

        for ($i = 0; $i < $processes; $i++) {
            $callables[] = fn (): string => $this->attempt($pair->refreshToken);
        }

        return $this->raceWith($callables);
    }

    /**
     * @param  list<callable(): string>  $callables
     * @return list<string>
     */
    private function raceWith(array $callables): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required to run rotations concurrently.');
        }

        $sockets = [];
        $children = [];

        foreach ($callables as $callable) {
            $pair = [];

            if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair) === false) {
                $this->fail('Unable to create a socket pair for the concurrency test.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork for the concurrency test.');
            }

            if ($pid === 0) {
                // Child: a forked process inherits the parent's connection, so
                // it has to open its own or the two would not contend at all.
                socket_close($pair[0]);
                $this->app->make('db')->purge();

                $result = $callable();

                socket_write($pair[1], $result);
                socket_close($pair[1]);

                exit(0);
            }

            socket_close($pair[1]);
            $sockets[] = $pair[0];
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $outcomes = [];

        foreach ($sockets as $socket) {
            $outcomes[] = (string) socket_read($socket, 64);
            socket_close($socket);
        }

        $this->app->make('db')->purge();

        return $outcomes;
    }

    /**
     * One rotation attempt, reduced to a word the parent can read back.
     */
    private function attempt(string $refreshToken): string
    {
        try {
            $this->app->make(RefreshTokenManager::class)->rotate($refreshToken);

            return 'ok';
        } catch (SanctumRefreshTokenException $e) {
            return $e->errorCode();
        }
    }
}
