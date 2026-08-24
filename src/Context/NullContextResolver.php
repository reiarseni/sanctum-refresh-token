<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Context;

use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

/**
 * The resolver used when context binding is switched off.
 *
 * It is never consulted in that state — the manager short-circuits before
 * reaching it — but having a real object here keeps the collaborator
 * non-nullable everywhere else.
 */
final class NullContextResolver implements ContextResolver
{
    public function resolve(): ?string
    {
        return null;
    }
}
