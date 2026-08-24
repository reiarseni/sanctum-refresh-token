<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;
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
     * Terminal rows whose terminal timestamp is older than the cutoff.
     *
     * @return Builder<RefreshToken>
     */
    private function candidates(Carbon $cutoff): Builder
    {
        return SanctumRefreshToken::query()->where(function (Builder $query) use ($cutoff): void {
            $query
                ->where(fn (Builder $revoked) => $revoked
                    ->whereNotNull('revoked_at')
                    ->where('revoked_at', '<', $cutoff))
                ->orWhere(fn (Builder $expired) => $expired
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<', $cutoff));
        });
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
