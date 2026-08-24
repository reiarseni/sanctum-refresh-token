<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Typed reads of the published configuration.
 *
 * Laravel's config repository returns `mixed`, and a configuration file is
 * consumer-owned: a value can be anything the consumer typed. Casting `mixed`
 * at each call site would mean a hand-edited string silently becoming `0`
 * minutes of expiry, or an array becoming `"Array"`. Every read goes through
 * one of these instead, and a value of the wrong shape falls back to the
 * documented default rather than being coerced into nonsense.
 */
final class Settings
{
    public function __construct(private readonly Config $config) {}

    public function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->config->get($key);

        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * An integer setting with a floor, since every one the package has is a
     * count or a duration and none of them is meaningfully negative.
     */
    public function int(string $key, int $default, int $minimum = 0): int
    {
        $value = $this->config->get($key, $default);

        if (is_int($value)) {
            return max($minimum, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max($minimum, (int) $value);
        }

        return $default;
    }

    /**
     * An integer setting where null is a meaningful value in itself: "no
     * expiry", "no limit".
     */
    public function nullableInt(string $key): ?int
    {
        $value = $this->config->get($key);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->config->get($key, $default);

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    public function stringList(string $key, array $default): array
    {
        $value = $this->config->get($key, $default);

        if (! is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings === [] ? $default : $strings;
    }

    public function raw(string $key): mixed
    {
        return $this->config->get($key);
    }
}
