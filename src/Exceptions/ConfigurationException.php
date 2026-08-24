<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

/**
 * The package refuses to boot under the given configuration.
 *
 * Raised at boot rather than at first use, so a deployment with an unsafe
 * secret length or an unsanitary identifier fails immediately and visibly
 * instead of issuing weaker tokens in production.
 */
final class ConfigurationException extends SanctumRefreshTokenException
{
    public static function unsafeIdentifier(string $key, string $value): self
    {
        return new self(sprintf(
            'The configured [%s] value "%s" is not a safe SQL identifier; '
            .'use only letters, digits and underscores, at most 64 characters.',
            $key,
            $value,
        ));
    }

    public static function secretTooShort(int $configured, int $minimum): self
    {
        return new self(sprintf(
            'sanctum-refresh-token.security.secret_bytes is %d, below the package minimum of %d bytes.',
            $configured,
            $minimum,
        ));
    }

    public static function invalidResolver(string $value): self
    {
        return new self(sprintf(
            'The configured context resolver [%s] does not implement %s.',
            $value,
            ContextResolver::class,
        ));
    }

    public static function missingApplicationKey(): self
    {
        return new self(
            'Client metadata hashing needs an application key; run `php artisan key:generate`.',
        );
    }

    public function errorCode(): string
    {
        return 'invalid_configuration';
    }
}
