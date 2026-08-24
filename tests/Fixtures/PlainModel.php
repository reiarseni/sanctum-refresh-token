<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that cannot hold Sanctum tokens. Issuance has to refuse it.
 */
class PlainModel extends Model
{
    protected $table = 'users';

    protected $guarded = [];
}
