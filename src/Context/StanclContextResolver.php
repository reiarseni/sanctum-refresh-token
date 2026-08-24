<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Context;

use Illuminate\Contracts\Container\Container;
use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

/**
 * Reads the current tenant key from stancl/tenancy.
 *
 * Instantiated only when that package is installed; see ContextResolverFactory.
 * Outside a tenant context it reports null, which refuses rotation of a bound
 * family rather than allowing it.
 *
 * The tenancy class is held as an injected string rather than referenced
 * directly, because the package is an optional dependency: this file has to
 * compile, analyse and run in an application where that class does not exist.
 */
final class StanclContextResolver implements ContextResolver
{
    public const TENANCY_CLASS = 'Stancl\\Tenancy\\Tenancy';

    public function __construct(
        private readonly Container $container,
        private readonly string $tenancyClass = self::TENANCY_CLASS,
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(self::TENANCY_CLASS);
    }

    public function resolve(): ?string
    {
        if (! class_exists($this->tenancyClass) || ! $this->container->bound($this->tenancyClass)) {
            return null;
        }

        $tenancy = $this->container->make($this->tenancyClass);

        if (! is_object($tenancy) || ! property_exists($tenancy, 'tenant')) {
            return null;
        }

        $tenant = $tenancy->tenant;

        if (! is_object($tenant) || ! method_exists($tenant, 'getTenantKey')) {
            return null;
        }

        $key = $tenant->getTenantKey();

        return is_scalar($key) ? (string) $key : null;
    }
}
