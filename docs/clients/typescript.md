# TypeScript / Axios

Single-flight refresh with a 409-aware retry. Works in the browser and in React
Native.

```ts
import axios, {
  AxiosError,
  AxiosInstance,
  InternalAxiosRequestConfig,
} from 'axios';

interface TokenPair {
  access_token: string;
  refresh_token: string;
  access_token_expires_at: string | null;
  refresh_token_expires_at: string | null;
}

interface TokenStore {
  read(): Promise<TokenPair | null>;
  write(pair: TokenPair): Promise<void>;
  clear(): Promise<void>;
}

/** Errors that mean the session is over. Anything else is recoverable. */
const TERMINAL = new Set([
  'refresh_token_reused',
  'refresh_token_revoked',
  'refresh_token_expired',
  'refresh_token_invalid',
  'family_expired',
]);

export class SessionExpiredError extends Error {
  constructor(readonly code: string) {
    super(`The session has ended (${code}).`);
  }
}

export function createClient(
  baseURL: string,
  store: TokenStore,
  onSessionExpired: () => void,
): AxiosInstance {
  const client = axios.create({ baseURL });

  // The whole point: at most one refresh is ever in flight. Concurrent
  // callers await this same promise instead of starting their own, which is
  // what stops an app from replaying its own consumed token.
  let inFlight: Promise<TokenPair> | null = null;

  async function refresh(): Promise<TokenPair> {
    if (inFlight) return inFlight;

    inFlight = (async () => {
      const current = await store.read();
      if (!current) throw new SessionExpiredError('no_refresh_token');

      try {
        const { data } = await axios.post<TokenPair>(
          `${baseURL}/auth/refresh`,
          { refresh_token: current.refresh_token },
        );

        await store.write(data);
        return data;
      } catch (error) {
        const status = (error as AxiosError).response?.status;
        const code =
          ((error as AxiosError<{ error?: string }>).response?.data?.error) ??
          'unknown';

        // 409: someone else's refresh landed first — possibly a second tab, or
        // this same app before a reload. The family is intact. Whatever is in
        // the store now is the token to use.
        if (status === 409 && code === 'rotation_in_progress') {
          const stored = await store.read();
          if (stored && stored.refresh_token !== current.refresh_token) {
            return stored;
          }
          throw new Error('rotation_in_progress');
        }

        if (TERMINAL.has(code)) {
          await store.clear();
          onSessionExpired();
          throw new SessionExpiredError(code);
        }

        throw error;
      } finally {
        inFlight = null;
      }
    })();

    return inFlight;
  }

  client.interceptors.request.use(async (config) => {
    const pair = await store.read();
    if (pair) config.headers.Authorization = `Bearer ${pair.access_token}`;
    return config;
  });

  client.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
      const original = error.config as
        | (InternalAxiosRequestConfig & { _retried?: boolean })
        | undefined;

      // Retry once and only once. A loop here turns one expired token into a
      // denial-of-service against your own API.
      if (error.response?.status !== 401 || !original || original._retried) {
        return Promise.reject(error);
      }

      original._retried = true;

      const pair = await refresh();
      original.headers.Authorization = `Bearer ${pair.access_token}`;

      return client(original);
    },
  );

  return client;
}
```

## Usage

```ts
const api = createClient(
  'https://api.example.com',
  secureTokenStore,
  () => router.push('/sign-in'),
);

await api.get('/orders');
```

## Notes

- **Storage.** In a browser, prefer an httpOnly cookie set by your own backend;
  `localStorage` is readable by any XSS. In React Native, use
  `expo-secure-store` or `react-native-keychain`.
- **Multiple tabs.** Two tabs are two `inFlight` variables, so they can still
  race each other — which is exactly what the grace window covers. If you want
  to eliminate it, coordinate through a `BroadcastChannel` or a
  `SharedWorker` and share one refresh across tabs.
- **Proactive refresh.** Read `access_token_expires_at` and renew ~60s early.
  Most 401s then never happen.
