# Kotlin / OkHttp

Single-flight refresh with a 409-aware retry, using OkHttp's `Authenticator`
plus a mutex.

```kotlin
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import okhttp3.Authenticator
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.Route

@Serializable
data class TokenPair(
    @SerialName("access_token") val accessToken: String,
    @SerialName("refresh_token") val refreshToken: String,
    @SerialName("access_token_expires_at") val accessTokenExpiresAt: String? = null,
)

@Serializable
private data class ErrorBody(val error: String = "unknown")

interface TokenStore {
    suspend fun read(): TokenPair?
    suspend fun write(pair: TokenPair)
    suspend fun clear()
}

class SessionExpiredException(val code: String) :
    Exception("The session has ended ($code).")

/** Errors that mean the session is over. Anything else is recoverable. */
private val TERMINAL = setOf(
    "refresh_token_reused",
    "refresh_token_revoked",
    "refresh_token_expired",
    "refresh_token_invalid",
    "family_expired",
)

private val json = Json { ignoreUnknownKeys = true }

/** Attaches the current access token to every outgoing request. */
class AuthInterceptor(private val store: TokenStore) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val pair = runBlocking { store.read() }

        val request = if (pair == null) {
            chain.request()
        } else {
            chain.request().newBuilder()
                .header("Authorization", "Bearer ${pair.accessToken}")
                .build()
        }

        return chain.proceed(request)
    }
}

/**
 * Refreshes on 401 and retries once.
 *
 * OkHttp calls an Authenticator on 401 and will not call it again for a
 * request it already re-signed, so the "retry once" rule comes for free —
 * `responseCount` guards the rest.
 */
class RefreshAuthenticator(
    private val baseUrl: String,
    private val store: TokenStore,
    private val client: OkHttpClient,
    private val onSessionExpired: () -> Unit,
) : Authenticator {

    // At most one refresh is ever in flight. Concurrent callers block on this
    // mutex and then find the token the winner already stored, which is what
    // stops the app from replaying its own consumed token.
    private val mutex = Mutex()

    override fun authenticate(route: Route?, response: Response): Request? {
        // Give up rather than loop: one expired token must not become a
        // denial-of-service against your own API.
        if (responseCount(response) >= 2) return null

        val failed = response.request.header("Authorization")

        val pair = runBlocking {
            mutex.withLock {
                val current = store.read() ?: return@withLock null

                // Somebody refreshed while this request was queued on the
                // mutex. Use their result instead of burning another rotation.
                if (failed != null && failed != "Bearer ${current.accessToken}") {
                    return@withLock current
                }

                refresh(current)
            }
        } ?: return null

        return response.request.newBuilder()
            .header("Authorization", "Bearer ${pair.accessToken}")
            .build()
    }

    private suspend fun refresh(current: TokenPair): TokenPair? {
        val body = """{"refresh_token":"${current.refreshToken}"}"""
            .toRequestBody("application/json".toMediaType())

        val request = Request.Builder()
            .url("$baseUrl/auth/refresh")
            .post(body)
            .header("Accept", "application/json")
            .build()

        client.newCall(request).execute().use { response ->
            val payload = response.body?.string().orEmpty()

            if (response.isSuccessful) {
                val pair = json.decodeFromString<TokenPair>(payload)
                store.write(pair)

                return pair
            }

            val code = runCatching {
                json.decodeFromString<ErrorBody>(payload).error
            }.getOrDefault("unknown")

            // 409: another refresh landed first. The family is intact;
            // whatever is in the store now is the token to use.
            if (response.code == 409 && code == "rotation_in_progress") {
                val stored = store.read()
                return if (stored != null && stored.refreshToken != current.refreshToken) {
                    stored
                } else {
                    null
                }
            }

            if (code in TERMINAL) {
                store.clear()
                onSessionExpired()
            }

            return null
        }
    }

    private fun responseCount(response: Response): Int {
        var count = 1
        var prior = response.priorResponse

        while (prior != null) {
            count++
            prior = prior.priorResponse
        }

        return count
    }
}
```

## Usage

```kotlin
// A bare client for the refresh call itself, so it cannot recurse back into
// the authenticator.
val refreshClient = OkHttpClient()

val api = OkHttpClient.Builder()
    .addInterceptor(AuthInterceptor(tokenStore))
    .authenticator(
        RefreshAuthenticator(
            baseUrl = "https://api.example.com",
            store = tokenStore,
            client = refreshClient,
            onSessionExpired = { navigateToSignIn() },
        )
    )
    .build()
```

## Notes

- **Storage.** `EncryptedSharedPreferences`, or the Keystore directly. Never
  plain `SharedPreferences`.
- **The `failed != current` check matters.** Several requests queue on the mutex;
  the first refreshes and the rest reuse its result. Without it each would
  rotate in turn, replaying a token consumed moments earlier.
- **`runBlocking` is correct here**: OkHttp's `Authenticator` is a synchronous
  API on a background thread.
