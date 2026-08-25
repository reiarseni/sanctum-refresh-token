<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Enums;

/**
 * Closed on purpose: `sanctum-refresh:doctor` breaks family mortality down by
 * reason, and a free-form string would make the difference between an attack
 * and a configuration problem unreadable.
 */
enum RevocationReason: string
{
    /** A consumed token was replayed outside the grace window. */
    case ReuseDetected = 'reuse_detected';

    case Logout = 'logout';

    case Revoked = 'revoked';

    case FamilyLimit = 'family_limit';

    case Expired = 'expired';

    case ContextMismatch = 'context_mismatch';
}
