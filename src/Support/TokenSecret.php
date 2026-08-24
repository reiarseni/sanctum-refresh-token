<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Support;

use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;

/**
 * Generation, formatting, hashing and timing-safe verification of the refresh
 * token secret.
 *
 * The plaintext token is `<row id>|<secret>`, the same shape Sanctum uses for
 * access tokens. Carrying the row identifier in the token means lookup is a
 * primary-key read rather than a scan over hashes, which in turn means the
 * secret comparison happens exactly once, in constant time, against exactly one
 * candidate.
 */
final class TokenSecret
{
    /**
     * The fewest random bytes the package will draw a secret from.
     *
     * 32 bytes is 256 bits of entropy, which is the point below which a stored
     * hash starts being worth attacking offline.
     */
    public const MINIMUM_BYTES = 32;

    private const SEPARATOR = '|';

    /**
     * @return int<self::MINIMUM_BYTES, max>
     *
     * @throws ConfigurationException when the configured length is unsafe
     */
    public static function assertSafeLength(int $bytes): int
    {
        if ($bytes < self::MINIMUM_BYTES) {
            throw ConfigurationException::secretTooShort($bytes, self::MINIMUM_BYTES);
        }

        return $bytes;
    }

    /**
     * A fresh secret drawn from the cryptographically secure source.
     *
     * `random_bytes` is the only generator used anywhere in this package.
     */
    public static function generate(int $bytes): string
    {
        return bin2hex(random_bytes(self::assertSafeLength($bytes)));
    }

    /**
     * The value persisted in the token column. The secret itself is never
     * stored, logged, or attached to an exception or an event.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * The plaintext handed to the client exactly once, at issuance.
     */
    public static function format(int|string $id, string $secret): string
    {
        return $id.self::SEPARATOR.$secret;
    }

    /**
     * Split a presented token into its identifier and its secret.
     *
     * Returns null for anything that is not two non-empty parts with a numeric
     * identifier, so a malformed token is rejected before any query runs.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $plaintext): ?array
    {
        if (! str_contains($plaintext, self::SEPARATOR)) {
            return null;
        }

        [$id, $secret] = explode(self::SEPARATOR, $plaintext, 2);

        if ($id === '' || $secret === '' || ! ctype_digit($id)) {
            return null;
        }

        return [$id, $secret];
    }

    /**
     * Timing-safe comparison of a presented secret against a stored hash.
     */
    public static function verify(string $presentedSecret, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($presentedSecret));
    }
}
