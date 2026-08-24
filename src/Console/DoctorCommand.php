<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Observability\GraceReplayRecorder;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;

/**
 * Reports why token families are dying.
 *
 * This is the command that answers the question the package exists to make
 * answerable. A rising `reuse_detected` count is an incident. A rising
 * `family_limit` or `context_mismatch` count is a configuration problem. A
 * rising grace-replay count with no reuse at all is a client without a
 * single-flight mutex. Without the breakdown all three look identical: users
 * complaining that they get logged out.
 */
class DoctorCommand extends Command
{
    protected $signature = 'sanctum-refresh:doctor
                            {--days=7 : The period to report over, in days}';

    protected $description = 'Report token family mortality broken down by revocation reason';

    public function handle(GraceReplayRecorder $graceReplays): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days);

        $this->components->info(sprintf('Token family mortality over the last %d day(s).', $days));

        /** @var array<string, int> $counts */
        $counts = SanctumRefreshToken::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '>=', $since)
            ->selectRaw('revocation_reason, count(distinct family_uuid) as families')
            ->groupBy('revocation_reason')
            ->pluck('families', 'revocation_reason')
            ->all();

        $rows = [];

        // Every reason is listed, including the ones at zero: an absent row and
        // a zero are different statements, and only one of them is reassuring.
        foreach (RevocationReason::cases() as $reason) {
            $rows[] = [$reason->value, (string) ($counts[$reason->value] ?? 0)];
        }

        $this->table(['Revocation reason', 'Families'], $rows);

        if (! $graceReplays->enabled()) {
            $this->components->warn(
                'Grace-period replays are not being recorded; enable '
                .'sanctum-refresh-token.observability.record_grace_replays to include them.',
            );

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail(
            'Grace-period replays',
            (string) $graceReplays->countSince($since),
        );

        return self::SUCCESS;
    }
}
