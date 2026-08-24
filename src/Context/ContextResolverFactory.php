<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Context;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;
use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;

/**
 * Builds the resolver named by configuration.
 *
 * The third-party drivers are referenced by string and only instantiated after
 * their package has been confirmed present, so the package boots cleanly in an
 * application that uses neither tenancy library — which is most of them.
 */
final class ContextResolverFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function make(): ContextResolver
    {
        if (! $this->bindingEnabled()) {
            return new NullContextResolver;
        }

        // A closure registered at runtime wins over the configuration file,
        // because it is the more specific statement of intent.
        $registered = SanctumRefreshToken::contextResolverCallback();

        if ($registered instanceof Closure) {
            return new ClosureContextResolver($registered);
        }

        $configured = $this->config->get('sanctum-refresh-token.context.resolver');

        if ($configured instanceof Closure) {
            return new ClosureContextResolver($configured);
        }

        if ($configured === null || $configured === '') {
            return $this->autodetect();
        }

        if (! is_string($configured)) {
            return new NullContextResolver;
        }

        return match ($configured) {
            'stancl' => $this->driver(
                fn (): ContextResolver => new StanclContextResolver($this->container),
                StanclContextResolver::isAvailable(),
                'stancl/tenancy',
            ),
            'spatie' => $this->driver(
                static fn (): ContextResolver => new SpatieContextResolver,
                SpatieContextResolver::isAvailable(),
                'spatie/laravel-multitenancy',
            ),
            default => $this->customClass($configured),
        };
    }

    public function bindingEnabled(): bool
    {
        return (bool) $this->config->get('sanctum-refresh-token.context.enabled', false);
    }

    /**
     * With binding on and no resolver named, use whichever tenancy package is
     * actually installed. Installing neither is not an error: the family simply
     * records a null context and is never bound.
     */
    private function autodetect(): ContextResolver
    {
        if (StanclContextResolver::isAvailable()) {
            return new StanclContextResolver($this->container);
        }

        if (SpatieContextResolver::isAvailable()) {
            return new SpatieContextResolver;
        }

        return new NullContextResolver;
    }

    /**
     * @param  callable(): ContextResolver  $make
     */
    private function driver(callable $make, bool $available, string $package): ContextResolver
    {
        if (! $available) {
            throw new ConfigurationException(sprintf(
                'The context resolver driver for [%s] was configured but that package is not installed.',
                $package,
            ));
        }

        return $make();
    }

    private function customClass(string $class): ContextResolver
    {
        if (! class_exists($class) || ! is_subclass_of($class, ContextResolver::class)) {
            throw ConfigurationException::invalidResolver($class);
        }

        /** @var ContextResolver $resolver */
        $resolver = $this->container->make($class);

        return $resolver;
    }
}
