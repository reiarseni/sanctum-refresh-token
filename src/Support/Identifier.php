<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Support;

use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;

/**
 * Validation of the configurable SQL identifiers the package writes into
 * schema-level statements.
 *
 * Table and column names cannot be passed as bound parameters, so they are the
 * one class of consumer-supplied value that reaches SQL as text. They are
 * therefore checked against a conservative pattern at boot, and the package
 * refuses to start rather than interpolating whatever it was given.
 */
final class Identifier
{
    /** Letters, digits and underscores only, not starting with a digit. */
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const MAX_LENGTH = 64;

    public static function isSafe(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * @throws ConfigurationException when the value is not a safe identifier
     */
    public static function assertSafe(string $value, string $configKey): string
    {
        if (! self::isSafe($value)) {
            throw ConfigurationException::unsafeIdentifier($configKey, $value);
        }

        return $value;
    }
}
