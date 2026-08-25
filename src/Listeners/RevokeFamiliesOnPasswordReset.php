<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Listeners;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;

/**
 * Revokes every family a user holds when their password is reset.
 *
 * Registered only when `security.revoke_on_password_reset` is on, because the
 * package does not log people out from listeners nobody asked for. Turning it
 * on is usually right: a user who resets a password believes they have just
 * locked everyone else out, and without this they have not.
 *
 * Every family goes, including the one that made the request. A reset arrives
 * through an emailed link and you cannot know who followed it — preserving "the
 * current session" would preserve the attacker's if the attacker is the one who
 * asked for the reset.
 *
 * A voluntary password change, where the user proved they knew the old
 * password, is a different case: use `$user->sessions()->revokeOthers()` there
 * and keep them signed in on the device they are holding.
 */
class RevokeFamiliesOnPasswordReset
{
    public function __construct(private readonly RefreshTokenManager $manager) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof Model) {
            return;
        }

        $this->manager->revokeAllFamilies($event->user, RevocationReason::Revoked);
    }
}
