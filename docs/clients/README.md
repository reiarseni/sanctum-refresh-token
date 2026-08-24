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
strict rotation usable.

## Also

- **Retry once, never in a loop.** If the retried request 401s again, stop.
- **Refresh proactively** when you know the access token's expiry: renewing a
  minute early avoids most 401s entirely.
- **Store refresh tokens in the platform's secure storage** — Keychain,
  EncryptedSharedPreferences, `flutter_secure_storage`. For browsers, prefer an
  httpOnly cookie set by your own backend; `localStorage` is readable by any XSS.

## Implementations

| | |
|---|---|
| [TypeScript / Axios](typescript.md) | web, React Native |
| [Dart / Dio](dart.md) | Flutter |
| [Swift / URLSession](swift.md) | iOS, macOS |
| [Kotlin / OkHttp](kotlin.md) | Android |

Each is a starting point, not a library: read it, adapt it, keep the mutex.
