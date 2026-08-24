<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Context;

use Closure;
use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

/**
 * Wraps an application-supplied closure, registered either in the published
 * configuration or through SanctumRefreshToken::resolveContextUsing().
 */
final class ClosureContextResolver implements ContextResolver
{
    /**
     * @param  Closure(): (string|int|null)  $callback
     */
    public function __construct(private readonly Closure $callback) {}

    public function resolve(): ?string
    {
        $value = ($this->callback)();

        return $value === null ? null : (string) $value;
    }
}
