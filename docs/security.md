# Security controls

This package makes specific, checkable claims. Every row below names a control,
the OWASP Top 10:2025 category it addresses, and the test that proves it. Run
them yourself:

```bash
vendor/bin/phpunit
```

**There is no OWASP compliance badge anywhere in this repository, and there
never will be.** A badge asserts coverage of ten categories; this package
addresses some of them and not others, and the honest form of that statement is
a table with gaps in it rather than an image.

## Controls implemented

| Control | OWASP Top 10:2025 | Proving test |
|---|---|---|
| Refresh secrets drawn from `random_bytes`, never `rand`/`mt_rand`/`uniqid` | A02 Cryptographic Failures | `TokenSecurityTest::the_default_secret_carries_at_least_256_bits_of_entropy` |
| Generated secrets are distinct across a large sample | A02 Cryptographic Failures | `TokenSecurityTest::generated_secrets_do_not_repeat` |
| A secret length below 32 bytes is refused at boot, not silently accepted | A05 Security Misconfiguration | `TokenSecurityTest::a_secret_length_below_the_minimum_is_refused_at_boot` |
| Only a SHA-256 hash of the secret is persisted | A02 Cryptographic Failures | `TokenSecurityTest::storage_holds_a_hash_and_never_the_secret` |
| Verification uses `hash_equals`, not `===` | A02 Cryptographic Failures | `TokenSecurityTest::verification_is_timing_safe_and_rejects_a_wrong_secret` |
| Unknown identifier and wrong secret are indistinguishable | A01 Broken Access Control | `RotationTest::a_wrong_secret_is_indistinguishable_from_an_unknown_identifier` |
| A malformed token is rejected before any secret verification | A01 Broken Access Control | `TokenSecurityTest::a_malformed_token_is_rejected_without_a_secret_verification` |
| Duplicate token hashes are impossible at the storage layer | A02 Cryptographic Failures | `MigrationsTest::the_token_column_is_unique` |
| No exception message echoes the presented token | A09 Logging and Alerting Failures | `RotationTest::assertRefusedWith` (asserted on every refusal path) |
| The token hash and metadata hashes are hidden from serialisation | A02 Cryptographic Failures | `TokenSecurityTest::the_token_column_is_hidden_from_serialisation` |
| Replaying a consumed token revokes the whole family | A07 Authentication Failures | `ReuseDetectionTest::a_replay_outside_the_grace_window_kills_the_whole_family` |
| Revocation reaches the legitimate holder's current generation too | A07 Authentication Failures | `ReuseDetectionTest::the_legitimate_clients_current_token_is_revoked_too` |
| Every access token of a revoked family stops authenticating | A01 Broken Access Control | `ReuseDetectionTest::every_access_token_of_the_family_stops_authenticating` |
| The superseded access token dies inside the rotation transaction | A01 Broken Access Control | `RotationTest::the_previous_access_token_stops_authenticating` |
| Rotation cannot widen a token's abilities | A01 Broken Access Control | `RotationTest::rotation_can_narrow_abilities_but_not_widen_them` |
| Concurrent rotations serialise instead of forking the family | A01 Broken Access Control | `ConcurrencyTest::two_concurrent_rotations_of_the_same_token_do_not_fork_the_family` † |
| A rotation racing a replay resolves to exactly one outcome | A01 Broken Access Control | `ConcurrencyTest::a_rotation_racing_a_replay_resolves_to_exactly_one_outcome` † |
| A generation created while a family is being revoked dies with it | A01 Broken Access Control | `ConcurrencyTest::a_rotation_racing_a_replay_resolves_to_exactly_one_outcome` † |
| Rotation is atomic: a mid-rotation failure leaves nothing behind | A04 Insecure Design | `RotationAtomicityTest::a_failure_mid_rotation_leaves_no_partial_state` |
| Cross-context isolation is an explicit check, not a global scope | A01 Broken Access Control | `IssuanceContextTest::isolation_does_not_depend_on_a_global_scope` |
| An unresolvable context fails closed | A04 Insecure Design | `IssuanceContextTest::an_unresolvable_context_refuses_rather_than_allowing_rotation` |
| A session belonging to another user cannot be revoked | A01 Broken Access Control | `SessionManagementTest::revoking_a_session_that_is_not_the_tokenables_is_refused` |
| Client metadata is keyed-hashed, not a bare digest | A02 Cryptographic Failures | `TokenSecurityTest::metadata_hashing_is_keyed_not_a_bare_digest` |
| Configurable identifiers are validated before reaching SQL | A03 Injection | `TokenSecurityTest::an_unsafe_configured_identifier_is_refused_at_boot` |
| No query interpolates a runtime value into raw SQL | A03 Injection | `TokenSecurityTest::no_query_interpolates_a_runtime_value_into_raw_sql` |
| Reuse, revocation and grace replays are all observable as events | A09 Logging and Alerting Failures | `ReuseDetectionTest::detection_dispatches_a_dedicated_event` |
| Events never carry plaintext token material | A09 Logging and Alerting Failures | `RotationTest::rotation_dispatches_an_event_carrying_no_plaintext` |
| Dependencies are resolved and tested at their lowest allowed versions | A06 Vulnerable and Outdated Components | CI job `lowest` in `.github/workflows/tests.yml` |
| A live credential is never deleted by maintenance | A04 Insecure Design | `StorageLifecycleTest::no_live_row_is_ever_pruned_whatever_its_age` |
| A configuration producing tokens that never expire is refused at boot | A05 Security Misconfiguration | `StorageLifecycleTest::both_lifetimes_null_is_refused_at_boot` |
| The family lock survives maintenance, so rotation cannot lose its serialisation point | A01 Broken Access Control | `StorageLifecycleTest::the_anchor_of_a_live_family_survives_pruning` |
| A password reset can revoke every session the user holds | A07 Authentication Failures | `SessionManagementTest::an_enabled_password_reset_listener_revokes_every_family` |

† These skip with an explicit reason on SQLite, which has no real
`SELECT ... FOR UPDATE`. They run for real against MySQL 8.4 and PostgreSQL 16
in the integration CI tier, and that tier fails the build if they skip there.

## Categories this package does not address

Listed rather than omitted, because a gap you cannot see is worse than one you
can.

| OWASP Top 10:2025 | Why it is out of scope |
|---|---|
| **A03 Injection** (beyond the package's own queries) | Only this package's SQL is covered. Your application's queries, templates and command execution are yours. |
| **A05 Security Misconfiguration** (beyond this package's own config) | Boot-time validation covers this package's settings. HTTPS, headers, CORS, `APP_DEBUG` and everything else are the application's. |
| **A06 Vulnerable and Outdated Components** | The lowest-dependency CI job proves the package installs on its floor. Keeping *your* dependencies patched is not something a package can do for you. |
| **A08 Software and Data Integrity Failures** | No supply-chain attestation, signed releases or SBOM is published yet. |
| **A09 Logging and Alerting Failures** | Events are dispatched; nothing listens by default. Alerting on `RefreshTokenReuseDetected` is a step you must take — see below. |
| **A10 Mishandling of Exceptional Conditions** | Every failure path here is typed and tested, but how your application responds to them is yours. |
| **Rate limiting and brute force** | Not attempted. Use Laravel's throttle middleware on your token endpoints; the published routes stub does. |
| **Two-factor authentication, password policy, account lockout** | Fortify's territory, not this package's. |
| **Deciding when a credential change ends a session** | The package supplies `revokeOthers()`, `revokeAll()` and an opt-in listener for password resets, and revokes nothing by default. Which of the three cases applies is yours to decide — see [sessions.md](sessions.md). |
| **Token binding (DPoP, mTLS)** | Bearer tokens only. RFC 9700 recommends sender-constrained tokens; the family model does not foreclose them, but they are not implemented. |

## Wire up the alarm

Reuse detection is only a security control if something is listening. The
package dispatches the event; registering a listener is one step and it is
yours:

```php
use Illuminate\Support\Facades\Event;
use Reiarseni\SanctumRefreshToken\Events\RefreshTokenReuseDetected;

Event::listen(function (RefreshTokenReuseDetected $event): void {
    Log::critical('Refresh token reuse detected', [
        'tokenable' => $event->tokenable->getKey(),
        'family' => $event->familyUuid,
        'replayed_generation' => $event->replayedGeneration,
        'current_generation' => $event->currentGeneration,
    ]);

    // Notify the user, page on-call, force a password reset — your call.
});
```

Then watch the rates with `php artisan sanctum-refresh:doctor`. A climbing
`reuse_detected` count is an incident. A climbing grace-replay count with no
reuse is a client missing a single-flight mutex.

## Threat model

**What this defends against.** A refresh token that leaks — from device
storage, a log, a proxy, a backup — being used by anyone other than its holder.
The moment either party refreshes after the other, the fork is visible and the
family dies. The attacker's window shrinks from "until the token expires" to
"until the real user next refreshes".

**What it does not defend against.** An attacker who steals a refresh token
*and* prevents the real user from ever refreshing again sees no detection,
because there is no second use to detect — the absolute family expiry
(`expiration.family`) is what bounds that case, which is why it defaults to 30
days rather than null. Neither does it help against a compromised server, a
compromised `APP_KEY`, or malware on the user's device reading tokens as they
are minted.

**The grace window is a real, if narrow, weakening.** An attacker replaying a
stolen token within the window gets a 409 rather than tripping the alarm, so
that theft goes unnoticed for up to `reuse_grace_period` seconds. The window is
short, configurable to zero, and every grace hit is observable through
`RefreshTokenReplayedInGracePeriod`. It buys correct behaviour for real users
on real networks, and that trade is made deliberately.
