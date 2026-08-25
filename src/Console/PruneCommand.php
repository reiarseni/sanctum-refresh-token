<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Support\Settings;

/**
 * Deletes terminal refresh token rows past the retention window.
 *
 * Retaining consumed rows is what makes reuse detection possible, so the table
 * grows monotonically until something trims it. The retention window is the
 * knob between two costs: too short and a replay a week after the fact is
 * invisible, too long and the table is mostly history.
 *
 * A row is only ever a candidate if it is already terminal — revoked or past
 * its own expiry. A live family cannot be pruned into unusability, and a
 * rotated-but-unexpired row survives so that its replay is still recognisable.
 */
class PruneCommand extends Command
{
    protected $signature = 'sanctum-refresh:prune
                            {--days= : Override the configured retention window, in days}
                            {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete expired and revoked refresh tokens past the retention window';

    public function handle(): int
    {
        $days = $this->retentionDays();
        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = $this->candidates($cutoff);
        $count = $query->count();

        if ($dryRun) {
            $this->components->info(sprintf(
                '%d refresh token row(s) are terminal and older than %d day(s); nothing was deleted.',
                $count,
                $days,
            ));

            return self::SUCCESS;
        }

        // Deleted in chunks so that pruning a large backlog does not hold one
        // enormous statement across a live table.
        $deleted = 0;

        while (($batch = $this->candidates($cutoff)->limit(1000)->pluck('id')->all()) !== []) {
            $removed = SanctumRefreshToken::query()->whereIn('id', $batch)->delete();

            $deleted += is_int($removed) ? $removed : 0;
        }

        $this->components->info(sprintf(
            'Deleted %d refresh token row(s) terminal for more than %d day(s).',
            $deleted,
            $days,
        ));

        return self::SUCCESS;
    }

    /**
     * Rows that have reached a terminal state and passed the retention window.
     *
     * Age is part of the predicate, not a nicety: a rotated row belonging to a
     * family configured without a token expiry carries neither `revoked_at` nor
     * `expires_at`, and without `created_at` no retention window could ever
     * reach it.
     *
     * @return Builder<RefreshToken>
     */
    private function candidates(Carbon $cutoff): Builder
    {
        return SanctumRefreshToken::query()
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->where(fn (Builder $revoked) => $revoked
                        ->whereNotNull('revoked_at')
                        ->where('revoked_at', '<', $cutoff))
                    ->orWhere(fn (Builder $expired) => $expired
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<', $cutoff))
                    ->orWhere(fn (Builder $old) => $old
                        ->whereNotNull('rotated_at')
                        ->whereNull('expires_at')
                        ->where('created_at', '<', $cutoff));
            })
            ->where(fn (Builder $query) => $query
                ->where('generation', '!=', RefreshTokenManager::ANCHOR_GENERATION)
                ->orWhereNotExists($this->familyStillLive()));
    }

    /**
     * A correlated existence check for any rotatable row in the same family.
     *
     * The anchor row carries the lock every rotation of the family takes, so
     * deleting it while the family is alive would leave live generations with
     * nothing to serialise against. Once the family holds nothing rotatable,
     * the anchor is prunable like any other row.
     *
     * @return Closure(QueryBuilder): void
     */
    private function familyStillLive(): Closure
    {
        $table = SanctumRefreshToken::newRefreshToken()->getTable();
        $now = Carbon::now();

        return static function (QueryBuilder $query) use ($table, $now): void {
            $query->select(DB::raw('1'))
                ->from($table.' as anchor_family')
                ->whereColumn('anchor_family.family_uuid', $table.'.family_uuid')
                ->whereNull('anchor_family.rotated_at')
                ->whereNull('anchor_family.revoked_at')
                ->where(fn (QueryBuilder $live) => $live
                    ->whereNull('anchor_family.expires_at')
                    ->orWhere('anchor_family.expires_at', '>', $now));
        };
    }

    private function retentionDays(): int
    {
        $option = $this->option('days');

        if (is_string($option) && $option !== '' && ctype_digit($option)) {
            return (int) $option;
        }

        return app(Settings::class)->int('sanctum-refresh-token.prune.retention_days', 7);
    }
}
