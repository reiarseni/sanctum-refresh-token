<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Observability;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;

/**
 * Counts benign refresh races.
 *
 * A grace-period replay is the one interesting outcome that leaves no row
 * behind: nothing is created, nothing is revoked, and the family is untouched.
 * That makes it invisible to a command that reads the table — and it is exactly
 * the number an operator needs, because a high replay rate with no reuse means
 * clients are racing themselves, not that anyone is under attack.
 *
 * Recording is off by default and costs a daily counter per day when on. The
 * log line carries the detail; the counter is what the doctor command sums.
 */
class GraceReplayRecorder
{
    private const PREFIX = 'sanctum-refresh-token:grace-replays:';

    /** Counters outlive any plausible reporting period, then expire. */
    private const TTL_DAYS = 120;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('sanctum-refresh-token.observability.record_grace_replays', false);
    }

    /**
     * Record one replay: a log line with the detail, and a counter for the day.
     */
    public function record(RefreshToken $row, float $secondsSinceRotation): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->logger()->info('sanctum-refresh-token.grace_replay', [
            'family' => $row->family_uuid,
            'generation' => $row->generation,
            'seconds_since_rotation' => round($secondsSinceRotation, 3),
        ]);

        $key = $this->key(Carbon::now());
        $store = $this->cache->store();

        // add() then increment(): some stores will not increment a key that was
        // never written.
        $store->add($key, 0, Carbon::now()->addDays(self::TTL_DAYS));
        $store->increment($key);
    }

    /**
     * How many replays were recorded from the given moment onwards.
     */
    public function countSince(Carbon $since): int
    {
        $store = $this->cache->store();
        $total = 0;

        for ($day = $since->copy()->startOfDay(); $day->lte(Carbon::now()); $day->addDay()) {
            $counted = $store->get($this->key($day), 0);

            $total += is_numeric($counted) ? (int) $counted : 0;
        }

        return $total;
    }

    private function key(Carbon $moment): string
    {
        return self::PREFIX.$moment->format('Y-m-d');
    }

    private function logger(): LoggerInterface
    {
        /** @var LogManager $manager */
        $manager = $this->container->make('log');

        $channel = $this->config->get('sanctum-refresh-token.observability.log_channel');

        return is_string($channel) && $channel !== '' ? $manager->channel($channel) : $manager;
    }
}
