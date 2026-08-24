<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Enums;

/**
 * What the package does when it recognises the replay of a consumed refresh
 * token outside the grace window.
 *
 * The call always fails with `refresh_token_reused` and the detection event is
 * always dispatched; the strategy only decides what gets revoked.
 */
enum ReuseStrategy: string
{
    /** RFC 9700 behaviour, and the default: the whole family dies. */
    case RevokeFamily = 'revoke_family';

    /** Only the replayed row is revoked; the rest of the family survives. */
    case RevokeToken = 'revoke_token';

    /** Nothing is revoked; the replay is recorded and refused. */
    case Observe = 'observe';
}
