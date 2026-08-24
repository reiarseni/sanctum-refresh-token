# Multi-tenant integration

A refresh token issued inside tenant `ACME` must never be usable inside
`GLOBEX`. This package enforces that by comparing two values under the same
lock that guards rotation — not by filtering a query.

## Why not a global scope?

The obvious Laravel answer is a global scope on the token model that filters by
the current tenant. It is the wrong tool for this job, for a specific reason:

**A scope that resolves its value from the container returns nothing when the
container has nothing to give, and a scope that filters on nothing filters
nothing.** It fails *open*. When the tenant context is not populated — a queued
job, a console command, an unusual guard, a middleware ordering bug — the scope
silently stops isolating and every tenant's tokens become visible to every
query.

A security control has to fail closed. So this package reads the context
recorded on the family, resolves the context of the current request, and
compares them explicitly:

```php
$recorded = $row->getAttribute($this->contextColumn());
$resolved = $this->resolveContext();

if ($resolved !== null && hash_equals($recorded, $resolved)) {
    // rotation proceeds
}
```

If the resolver returns `null` — no tenant established — a family that recorded
a context is **refused**, not allowed. That is the whole difference.

You are still free to add a global scope of your own; the model is replaceable.
The package's guarantee just does not depend on you having done so, and its
tests prove it with a model carrying no scope at all.

## Setting it up

```bash
php artisan vendor:publish --tag=sanctum-refresh-token-context-migration
php artisan migrate
```

```php
// config/sanctum-refresh-token.php
'context' => [
    'enabled'     => true,
    'column'      => 'tenant_code',  // whatever your schema calls it
    'resolver'    => null,           // null, 'stancl', 'spatie', or a class name
    'on_mismatch' => 'reject',       // or 'revoke_family'
],
```

## Resolvers

### A closure — the simplest option

Register it in a service provider:

```php
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;

public function boot(): void
{
    SanctumRefreshToken::resolveContextUsing(
        fn (): ?string => auth()->user()?->tenant_code,
    );
}
```

A closure registered here wins over anything named in the config file.

### A class

```php
namespace App\Support;

use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;

class TenantResolver implements ContextResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(): ?string
    {
        // Return null when there is no tenant. Do not guess, and do not
        // fall back to a default: null refuses the rotation, which is
        // the safe answer.
        return $this->context->currentCode();
    }
}
```

```php
'resolver' => \App\Support\TenantResolver::class,
```

It is resolved through the container, so constructor injection works.

### stancl/tenancy and spatie/laravel-multitenancy

```php
'resolver' => 'stancl',  // or 'spatie'
```

Both are optional dependencies, referenced by string and only instantiated when
the package is actually installed. Naming a driver whose package is missing
raises a configuration exception rather than failing silently. Leaving
`resolver` as `null` autodetects whichever of the two is present, and falls back
to recording no context if neither is.

## What happens on a mismatch

By default: the rotation is refused with `context_mismatch`, the family stays
live, and a `ContextMismatchDetected` event fires carrying both values.

**A mismatch is not treated as an attack**, and that is deliberate. It is far
more often a misconfigured resolver, a middleware ordering problem, or a user
whose tenant assignment changed. Revoking a family over a configuration error
is a self-inflicted outage.

If you want the stricter reading:

```php
'on_mismatch' => 'revoke_family',
```

The family is then revoked with the reason `context_mismatch` — still
distinguishable from `reuse_detected` in `sanctum-refresh:doctor`, so you can
tell a misconfiguration from an incident.

## Rules the package follows

- **The context is recorded at issuance and copied forward on rotation,
  verbatim.** It is never re-resolved, or a user whose tenant changed would
  silently drag an old family into a new tenant.
- **Copying forward does not depend on binding staying enabled.** Switch it off,
  rotate, switch it back on, and the families are still bound to what they were.
- **A family with a null context is unbound** and rotates in any context.
  Families opened before you enabled binding keep working.
- **Session listing is scoped too.** `$user->sessions()->all()` in tenant B does
  not show tenant A's sessions, and `revoke()` refuses a family from another
  tenant with `session_not_found` — the same answer it gives for a family that
  does not exist, so the endpoint cannot be used to probe.

## Beyond tenants

The context is any application-defined discriminator. A region (`eu-west`), a
brand, a channel (`mobile` vs `partner-api`), an environment — anything you need
a token to stay inside. The package neither knows nor cares what the string
means; it only requires that the same request answers the same way.
