<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Context;

use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

/**
 * Reads the current tenant key from spatie/laravel-multitenancy.
 *
 * Instantiated only when that package is installed; see ContextResolverFactory.
 *
 * The tenant class is held as an injected string rather than referenced
 * directly, because the package is an optional dependency: this file has to
 * compile, analyse and run in an application where that class does not exist.
 * The constructor argument also lets the tests substitute a stand-in.
 */
final class SpatieContextResolver implements ContextResolver
{
    public const TENANT_CLASS = 'Spatie\\Multitenancy\\Models\\Tenant';

    public function __construct(private readonly string $tenantClass = self::TENANT_CLASS) {}

    public static function isAvailable(): bool
    {
        return class_exists(self::TENANT_CLASS);
    }

    public function resolve(): ?string
    {
        $tenant = $this->currentTenant();

        if (! is_object($tenant) || ! method_exists($tenant, 'getKey')) {
            return null;
        }

        $key = $tenant->getKey();

        return is_scalar($key) ? (string) $key : null;
    }

    private function currentTenant(): mixed
    {
        $class = $this->tenantClass;

        if (! class_exists($class) || ! method_exists($class, 'current')) {
            return null;
        }

        return $class::current();
    }
}
