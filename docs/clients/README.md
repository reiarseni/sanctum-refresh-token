# Reference clients

Strict rotation puts a burden on the client, and this is where
implementations fail. These four do it correctly.

## The contract

1. A request comes back **401** → refresh, then retry the request once.
2. Refresh comes back **200** → store the new pair, retry.
3. Refresh comes back **409 `rotation_in_progress`** → **another refresh is
   already in flight or just finished. Wait for it and use its result. Do not
   log the user out.**
4. Refresh comes back **401** with any other code (`refresh_token_reused`,
   `refresh_token_revoked`, `refresh_token_expired`, `family_expired`) → the
   session is genuinely over. Clear the tokens and send the user to sign-in.

## The one rule

**Never let two refreshes run at once.** Every implementation here holds a
single in-flight refresh promise; concurrent callers await the same one rather
than starting their own.

Without this, a screen that fires five parallel requests on load produces five
simultaneous refreshes. One wins; the other four replay a token that was
consumed milliseconds earlier. Inside the grace window that is four 409s. Set
`reuse_grace_period` to zero and it is a revoked family — the user is logged
out by their own app opening a screen.

The single-flight mutex is not an optimisation. It is the thing that makes
strict rotation usable. The package's own test suite demonstrates it: two
callers sharing one in-flight refresh produce one request, no 409, and a single
new generation.

## If you cannot fix the client

Sometimes the client is a shipped binary, or an SDK you do not control. For that
case the package can answer a replay inside the window with a fresh pair instead
of a 409:

```php
// config/sanctum-refresh-token.php
'rotation' => [
    'on_grace_replay' => 'reissue',
],
```

Every large identity provider defaults to this behaviour — Auth0 calls it the
rotation overlap period, Okta allows 0–60 seconds with 30 as the default,
Cognito allows up to 60, Ory Hydra up to five minutes. They serve estates of
legacy clients they do not control, and compatibility wins.

Ory is the one that states the price plainly, and it applies here word for word:

> Enabling graceful token rotation **effectively disables this security feature**
> for the duration of the grace period, because the same refresh token can be
> redeemed multiple times without triggering reuse detection.

They call it *"a workaround, not a best practice"*, and so does this package.
Concretely, inside the window:

- A replay gets a **working credential**. If the party replaying is an attacker,
  you have just helped them, and the alarm does not sound.
- Every redemption writes a row. A client looping without a mutex will grow the
  table; see [operations](../operations.md) for pruning.
- Outside the window nothing changes: a replay is still reuse, and the family
  still dies.

`sanctum-refresh:doctor` keeps counting grace replays under this mode, which
matters more here than under the default — the client no longer sees an error,
so the report is the only place the underlying problem is still visible.

Fix the client if you can. This is the door for when you cannot.

## Also

- **Retry once, never in a loop.** If the retried request 401s again, stop.
- **Refresh proactively** when you know the access token's expiry: renewing a
  minute early avoids most 401s entirely, and on a mobile network every avoided
  round trip is real latency.
- **Store refresh tokens in the platform's secure storage** — Keychain,
  EncryptedSharedPreferences, `flutter_secure_storage`.

## In a browser, think twice

`localStorage` is readable by any XSS, and a refresh token is a fourteen-day
renewable credential. Putting one there is strictly worse than an httpOnly
session cookie: the cookie cannot be read by script at all.

So in a browser:

1. **If your SPA is on the same domain or a subdomain of your API**, use
   [Sanctum's SPA mode](https://laravel.com/docs/sanctum#spa-authentication)
   instead — session cookie plus CSRF. You do not need this package, and that is
   the honest answer.
2. **If it is on a different origin** and cookies are not an option, keep the
   **access token in memory** (a variable, not storage — it dies with the tab,
   which is fine, you can refresh) and the **refresh token in an httpOnly cookie
   your own backend sets** on the token endpoint. The client never touches it;
   the browser attaches it to the refresh call.
3. **Only if neither is possible** does `localStorage` come into it, and then
   shorten `expiration.refresh_token` hard — hours, not weeks — and treat the
   `RefreshTokenReuseDetected` event as a genuine alarm.

The reference client below assumes it is handed a store; what that store is
backed by is the decision above, and it matters more than the code.

## Implementations

| | |
|---|---|
| [TypeScript / Axios](typescript.md) | web, React Native |
| [Dart / Dio](dart.md) | Flutter |
| [Swift / URLSession](swift.md) | iOS, macOS |
| [Kotlin / OkHttp](kotlin.md) | Android |

Each is a starting point, not a library: read it, adapt it, keep the mutex.
