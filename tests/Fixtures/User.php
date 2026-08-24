<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Reiarseni\SanctumRefreshToken\Concerns\HasRefreshTokens;

/**
 * The ordinary tokenable: Sanctum's trait plus this package's.
 *
 * Deliberately carries no global scope of any kind, so that the cross-context
 * isolation tests prove the package's own check rather than Eloquent's
 * filtering.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasRefreshTokens;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];
}
