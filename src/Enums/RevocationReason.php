<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Enums;

/**
 * The closed set of reasons a refresh token row may be revoked for.
 *
 * The set is deliberately closed: `sanctum-refresh:doctor` breaks family
 * mortality down by reason, and a free-form string would make the difference
 * between an attack and a configuration problem unreadable.
 */
enum RevocationReason: string
{
    /** A consumed token was replayed outside the grace window. */
    case ReuseDetected = 'reuse_detected';

    /** The holder logged out explicitly. */
    case Logout = 'logout';

    /** The application revoked the family through the session manager. */
    case Revoked = 'revoked';

    /** Issuance exceeded the configured concurrent family limit. */
    case FamilyLimit = 'family_limit';

    /** The family passed its absolute expiry and was cleaned up. */
    case Expired = 'expired';

    /** A rotation was attempted from a context the family is not bound to. */
    case ContextMismatch = 'context_mismatch';
}
