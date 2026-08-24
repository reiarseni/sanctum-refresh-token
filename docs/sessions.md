# Sessions and "your devices"

A token family *is* a session. One login opens one family; every refresh
advances it; revoking it logs that device out. So the package exposes families
as immutable `Session` objects, and you never touch its Eloquent models.

## A working endpoint

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Reiarseni\SanctumRefreshToken\Http\Resources\SessionResource;

class SessionController extends Controller
{
    public function index(Request $request)
    {
        return SessionResource::collection($request->user()->sessions()->all());
    }

    public function update(Request $request, string $family)
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
        ]);

        return SessionResource::make(
            $request->user()->sessions()->rename($family, $validated['label']),
        );
    }

    public function destroy(Request $request, string $family)
    {
        $request->user()->sessions()->revoke($family);

        return response()->noContent();
    }

    public function destroyOthers(Request $request)
    {
        return response()->json([
            'revoked' => $request->user()->sessions()->revokeOthers(),
        ]);
    }
}
```

```php
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('sessions', [SessionController::class, 'index']);
    Route::patch('sessions/{family}', [SessionController::class, 'update']);
    Route::delete('sessions/{family}', [SessionController::class, 'destroy']);
    Route::delete('sessions', [SessionController::class, 'destroyOthers']);
});
```

Response:

```json
{
  "data": [
    {
      "id": "9a3f1c8e-5d2b-4f7a-b1c6-0e4d8a2f6b91",
      "label": "Rei's iPhone",
      "device": {
        "platform": null, "application": null, "operating_system": null,
        "ip_address": null, "user_agent": null, "available": false
      },
      "is_current": true,
      "generation": 7,
      "created_at": "2026-08-10T09:14:22+00:00",
      "last_used_at": "2026-08-24T16:03:51+00:00",
      "expires_at": "2026-09-07T09:14:22+00:00",
      "family_expires_at": "2026-09-09T09:14:22+00:00"
    }
  ]
}
```

The resource is built over the value object, not the row, so it cannot leak a
token hash, a metadata hash or a database id — those never reach it.

## Why the device fields are null

By default, observed metadata (IP address, user agent) is stored as an
HMAC keyed on your `APP_KEY`. That is enough to notice the device changed and
useless for reconstructing what it was, which is why `available` is `false` and
every readable field is `null`.

If you want a readable device list, opt in:

```php
// config/sanctum-refresh-token.php
'security' => [
    'store_metadata_plaintext' => true,
],
```

Now the same session reports:

```json
"device": {
  "platform": "mobile",
  "application": "Safari",
  "operating_system": "iOS",
  "ip_address": "203.0.113.7",
  "user_agent": "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) ...",
  "available": true
}
```

**This is a data-protection decision and the default is deliberately the private
one.** Storing users' IP addresses is a choice you should make on purpose, with
whatever retention and disclosure obligations follow in your jurisdiction — not
one you inherit from a package default. The setting only affects rows written
after you change it; existing hashes stay hashed.

Ask the client for the label instead, which needs no personal data at all:

```php
$manager->issue($user, TokenConfig::make()->withName($request->input('device_name')));
```

## `is_current`

Exactly one session is flagged current: the family that issued the access token
authenticating this request. Outside an authenticated request nothing is
flagged and nothing errors — `sessions()->current()` simply returns `null`.

This is why sessions are a read model rather than exposed rows: `is_current`
depends on request state, not on any column, and no Eloquent model could
express it.

## Immutability

`Session` properties are readonly. Mutating one is a fatal error, not a silent
no-op that never reaches the database. Changes go through named methods:

```php
$sessions = $user->sessions();

$sessions->rename($family, 'Work laptop');   // validated: length, control chars
$sessions->revoke($family);                  // one device
$sessions->revokeOthers();                   // "sign out everywhere else"
$sessions->revokeAll();                      // including this one
```

Renaming writes the label to every generation of the family, so a later
rotation does not resurrect the old name.

## Limiting concurrent sessions

```php
'rotation' => [
    'max_concurrent_families' => 5,
],
```

A sixth login revokes the least recently used family with the reason
`family_limit` — distinguishable in `sanctum-refresh:doctor` from an
attack-driven revocation, which matters when you are reading that report during
an incident. `null` disables the limit.

## Revoking on password change or deactivation

The package does not register listeners for you. Wire the recipe you want:

```php
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;

// After a password change or reset:
$user->revokeAllTokenFamilies(RevocationReason::Revoked);

// On deactivation, keeping the current session alive is usually wrong:
$user->sessions()->revokeAll();
```

This is deliberately not automatic. A package that logs your users out from a
listener you never registered is a package that will surprise you at the worst
possible moment.
