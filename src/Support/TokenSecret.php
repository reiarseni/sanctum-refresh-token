<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Support;

use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;

/**
 * The plaintext token is `<row id>|<secret>`, the shape Sanctum uses. Carrying
 * the id makes lookup a primary-key read rather than a scan over hashes, so the
 * secret is compared exactly once, in constant time, against one candidate.
 */
final class TokenSecret
{
    /**
     * 256 bits: the point below which a stored hash starts being worth
     * attacking offline.
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
     * `random_bytes` is the only generator used anywhere in this package.
     */
    public static function generate(int $bytes): string
    {
        return bin2hex(random_bytes(self::assertSafeLength($bytes)));
    }

    /**
     * The secret itself is never stored, logged, or attached to an exception.
     */
    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * Handed to the client exactly once, at issuance.
     */
    public static function format(int|string $id, string $secret): string
    {
        return $id.self::SEPARATOR.$secret;
    }

    /**
     * Null for anything that is not two non-empty parts with a numeric id, so a
     * malformed token is rejected before any query runs.
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

    public static function verify(string $presentedSecret, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($presentedSecret));
    }
}
