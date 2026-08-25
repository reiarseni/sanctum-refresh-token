# Swift / URLSession

Single-flight refresh with a 409-aware retry, using an actor so the mutex is
enforced by the compiler rather than by convention.

```swift
import Foundation

struct TokenPair: Codable, Sendable {
    let accessToken: String
    let refreshToken: String
    let accessTokenExpiresAt: Date?

    enum CodingKeys: String, CodingKey {
        case accessToken = "access_token"
        case refreshToken = "refresh_token"
        case accessTokenExpiresAt = "access_token_expires_at"
    }
}

protocol TokenStore: Sendable {
    func read() async -> TokenPair?
    func write(_ pair: TokenPair) async
    func clear() async
}

enum AuthError: Error {
    /// The session is genuinely over. Send the user to sign-in.
    case sessionExpired(code: String)
    /// A refresh raced another and lost. Retryable.
    case rotationInProgress
    case server(status: Int)
}

private struct ErrorBody: Decodable {
    let error: String
}

/// Errors that mean the session is over. Anything else is recoverable.
private let terminalCodes: Set<String> = [
    "refresh_token_reused",
    "refresh_token_revoked",
    "refresh_token_expired",
    "refresh_token_invalid",
    "family_expired",
]

actor APIClient {
    private let baseURL: URL
    private let store: TokenStore
    private let session: URLSession
    private let onSessionExpired: @Sendable () -> Void

    /// At most one refresh is ever in flight. Because this is an actor, that
    /// invariant is enforced by isolation rather than by discipline:
    /// concurrent callers await this same task instead of starting their own.
    private var inFlight: Task<TokenPair, Error>?

    init(
        baseURL: URL,
        store: TokenStore,
        session: URLSession = .shared,
        onSessionExpired: @escaping @Sendable () -> Void
    ) {
        self.baseURL = baseURL
        self.store = store
        self.session = session
        self.onSessionExpired = onSessionExpired
    }

    func send(_ request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        var authorized = request

        if let pair = await store.read() {
            authorized.setValue("Bearer \(pair.accessToken)",
                                forHTTPHeaderField: "Authorization")
        }

        let (data, response) = try await session.data(for: authorized)
        guard let http = response as? HTTPURLResponse else {
            throw AuthError.server(status: -1)
        }

        guard http.statusCode == 401 else {
            return (data, http)
        }

        // Retry once and only once. A loop turns one expired token into a
        // denial-of-service against your own API.
        let pair = try await refresh()

        var retried = request
        retried.setValue("Bearer \(pair.accessToken)",
                         forHTTPHeaderField: "Authorization")

        let (retryData, retryResponse) = try await session.data(for: retried)
        guard let retryHTTP = retryResponse as? HTTPURLResponse else {
            throw AuthError.server(status: -1)
        }

        return (retryData, retryHTTP)
    }

    private func refresh() async throws -> TokenPair {
        if let existing = inFlight {
            return try await existing.value
        }

        let task = Task<TokenPair, Error> { [store, session, baseURL] in
            guard let current = await store.read() else {
                throw AuthError.sessionExpired(code: "no_refresh_token")
            }

            var request = URLRequest(url: baseURL.appendingPathComponent("auth/refresh"))
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.setValue("application/json", forHTTPHeaderField: "Accept")
            request.httpBody = try JSONEncoder().encode(
                ["refresh_token": current.refreshToken]
            )

            let (data, response) = try await session.data(for: request)
            guard let http = response as? HTTPURLResponse else {
                throw AuthError.server(status: -1)
            }

            if http.statusCode == 200 {
                let decoder = JSONDecoder()
                decoder.dateDecodingStrategy = .iso8601

                let pair = try decoder.decode(TokenPair.self, from: data)
                await store.write(pair)

                return pair
            }

            let code = (try? JSONDecoder().decode(ErrorBody.self, from: data))?.error
                ?? "unknown"

            // 409: another refresh landed first. The family is intact; whatever
            // is in the store now is the token to use.
            if http.statusCode == 409, code == "rotation_in_progress" {
                if let stored = await store.read(),
                   stored.refreshToken != current.refreshToken {
                    return stored
                }
                throw AuthError.rotationInProgress
            }

            if terminalCodes.contains(code) {
                await store.clear()
                throw AuthError.sessionExpired(code: code)
            }

            throw AuthError.server(status: http.statusCode)
        }

        inFlight = task

        defer { inFlight = nil }

        do {
            return try await task.value
        } catch let error as AuthError {
            if case .sessionExpired = error {
                onSessionExpired()
            }
            throw error
        }
    }
}
```

## Usage

```swift
let client = APIClient(
    baseURL: URL(string: "https://api.example.com")!,
    store: KeychainTokenStore(),
    onSessionExpired: { Task { @MainActor in router.showSignIn() } }
)

let (data, response) = try await client.send(
    URLRequest(url: URL(string: "https://api.example.com/orders")!)
)
```

## Notes

- **Storage.** Keychain with `kSecAttrAccessibleAfterFirstUnlock`, so background
  refreshes work. Never `UserDefaults` — it is a plist in the app container.
- **App resume** fires several requests at once; the actor's isolation is what
  stops that becoming several simultaneous refreshes.
