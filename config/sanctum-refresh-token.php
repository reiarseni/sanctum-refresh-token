<?php

declare(strict_types=1);

use Reiarseni\SanctumRefreshToken\Enums\ReuseStrategy;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | The table holding refresh token rows and the Eloquent model mapped onto
    | it. Both names are validated against a safe identifier pattern at boot,
    | because they end up in schema-level statements that cannot be bound.
    |
    */

    'table' => 'refresh_tokens',

    'model' => RefreshToken::class,

    /*
    |--------------------------------------------------------------------------
    | Expiration
    |--------------------------------------------------------------------------
    |
    | Lifetimes in minutes. "family" is the absolute cap on a family's total
    | duration regardless of how many rotations happen inside it; null leaves
    | families uncapped.
    |
    */

    'expiration' => [
        'access_token' => 15,
        'refresh_token' => 60 * 24 * 14,
        'family' => 60 * 24 * 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rotation
    |--------------------------------------------------------------------------
    |
    | reuse_grace_period is the number of seconds after a rotation during which
    | replaying the consumed token is read as a benign retry rather than as
    | theft. Set it to 0 for strict RFC 9700 behaviour.
    |
    | max_concurrent_families caps the live families a single tokenable may
    | hold; exceeding it revokes the least recently used one. null disables it.
    |
    */

    'rotation' => [
        'reuse_grace_period' => 10,
        'reuse_strategy' => ReuseStrategy::RevokeFamily,
        'max_concurrent_families' => null,
        'default_abilities' => ['*'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | secret_bytes is the number of cryptographically secure random bytes each
    | refresh token secret is drawn from. Values below the package minimum are
    | refused at boot rather than silently weakening tokens.
    |
    | store_metadata_plaintext turns off keyed hashing of the observed client
    | metadata. Enabling it is a data-protection decision: take it knowingly.
    |
    */

    'security' => [
        'secret_bytes' => 32,
        'store_metadata_plaintext' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Issuance context binding
    |--------------------------------------------------------------------------
    |
    | Binds every family to an application-defined discriminator (a tenant, a
    | region, anything) and verifies it on rotation by explicit comparison,
    | never by an Eloquent global scope.
    |
    | resolver accepts null, a closure, or the class name of a
    | Contracts\ContextResolver implementation. The shipped drivers
    | 'stancl' and 'spatie' are only instantiated when their package is
    | installed.
    |
    | on_mismatch: 'reject' leaves the family live, 'revoke_family' kills it.
    |
    */

    'context' => [
        'enabled' => false,
        'column' => 'context',
        'resolver' => null,
        'on_mismatch' => 'reject',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    */

    'session' => [
        'default_label' => 'Unnamed device',
        'max_label_length' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    |
    | Grace-period replays leave no row behind, so the doctor command can only
    | report them when they are being recorded somewhere. Enabling this writes
    | one line per replay to the configured log channel; the doctor command
    | reads it back.
    |
    */

    'observability' => [
        'record_grace_replays' => false,
        'log_channel' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prune
    |--------------------------------------------------------------------------
    |
    | Days a terminal (expired or revoked) row is retained before the prune
    | command may delete it. The default keeps a week of history so a recent
    | incident is still investigable.
    |
    */

    'prune' => [
        'retention_days' => 7,

        /*
         | Register the prune command on Laravel's scheduler. false leaves the
         | scheduler untouched; a frequency method ('daily', 'hourly', ...) or a
         | cron expression registers it. Off by default -- but a table nobody
         | prunes grows forever, and `sanctum-refresh:doctor` will say so.
         */
        'schedule' => false,
    ],

];
