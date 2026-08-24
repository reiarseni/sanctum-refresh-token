<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Contracts;

/**
 * Supplies the issuance context of the current request.
 *
 * A resolver returns a scalar discriminator — a tenant code, a region, a brand,
 * whatever the application isolates on — or null when there is no context to
 * report. Returning null is not a neutral answer: for a family that recorded a
 * context, an unresolvable context refuses the rotation, because a control that
 * cannot tell where it is has to fail closed.
 */
interface ContextResolver
{
    /**
     * The context of the current request, or null when none is established.
     */
    public function resolve(): ?string;
}
