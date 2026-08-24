# Dart / Dio

Single-flight refresh with a 409-aware retry, for Flutter.

```dart
import 'dart:async';

import 'package:dio/dio.dart';

class TokenPair {
  const TokenPair({
    required this.accessToken,
    required this.refreshToken,
    this.accessTokenExpiresAt,
  });

  final String accessToken;
  final String refreshToken;
  final DateTime? accessTokenExpiresAt;

  factory TokenPair.fromJson(Map<String, dynamic> json) => TokenPair(
        accessToken: json['access_token'] as String,
        refreshToken: json['refresh_token'] as String,
        accessTokenExpiresAt: json['access_token_expires_at'] == null
            ? null
            : DateTime.parse(json['access_token_expires_at'] as String),
      );
}

abstract class TokenStore {
  Future<TokenPair?> read();
  Future<void> write(TokenPair pair);
  Future<void> clear();
}

class SessionExpiredException implements Exception {
  const SessionExpiredException(this.code);
  final String code;

  @override
  String toString() => 'The session has ended ($code).';
}

/// Errors that mean the session is over. Anything else is recoverable.
const _terminal = {
  'refresh_token_reused',
  'refresh_token_revoked',
  'refresh_token_expired',
  'refresh_token_invalid',
  'family_expired',
};

class RefreshInterceptor extends QueuedInterceptor {
  RefreshInterceptor({
    required this.dio,
    required this.store,
    required this.baseUrl,
    required this.onSessionExpired,
  });

  final Dio dio;
  final TokenStore store;
  final String baseUrl;
  final void Function() onSessionExpired;

  /// At most one refresh is ever in flight. Concurrent callers await this same
  /// future rather than starting their own, which is what stops the app from
  /// replaying its own consumed token.
  Future<TokenPair>? _inFlight;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final pair = await store.read();
    if (pair != null) {
      options.headers['Authorization'] = 'Bearer ${pair.accessToken}';
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final request = err.requestOptions;

    // Retry once and only once. A loop turns one expired token into a
    // denial-of-service against your own API.
    if (err.response?.statusCode != 401 || request.extra['retried'] == true) {
      return handler.next(err);
    }

    request.extra['retried'] = true;

    try {
      final pair = await _refresh();
      request.headers['Authorization'] = 'Bearer ${pair.accessToken}';

      handler.resolve(await dio.fetch(request));
    } on SessionExpiredException {
      handler.next(err);
    } catch (_) {
      handler.next(err);
    }
  }

  Future<TokenPair> _refresh() {
    return _inFlight ??= _performRefresh().whenComplete(() => _inFlight = null);
  }

  Future<TokenPair> _performRefresh() async {
    final current = await store.read();
    if (current == null) {
      throw const SessionExpiredException('no_refresh_token');
    }

    // A bare Dio, so this request cannot recurse back into this interceptor.
    final refreshClient = Dio(BaseOptions(baseUrl: baseUrl));

    try {
      final response = await refreshClient.post<Map<String, dynamic>>(
        '/auth/refresh',
        data: {'refresh_token': current.refreshToken},
      );

      final pair = TokenPair.fromJson(response.data!);
      await store.write(pair);

      return pair;
    } on DioException catch (e) {
      final code = (e.response?.data is Map)
          ? (e.response!.data as Map)['error'] as String? ?? 'unknown'
          : 'unknown';

      // 409: another refresh landed first. The family is intact; whatever is
      // in the store now is the token to use.
      if (e.response?.statusCode == 409 && code == 'rotation_in_progress') {
        final stored = await store.read();
        if (stored != null && stored.refreshToken != current.refreshToken) {
          return stored;
        }
        rethrow;
      }

      if (_terminal.contains(code)) {
        await store.clear();
        onSessionExpired();
        throw SessionExpiredException(code);
      }

      rethrow;
    }
  }
}
```

## Usage

```dart
final dio = Dio(BaseOptions(baseUrl: 'https://api.example.com'));

dio.interceptors.add(RefreshInterceptor(
  dio: dio,
  store: secureTokenStore,
  baseUrl: 'https://api.example.com',
  onSessionExpired: () => navigatorKey.currentState?.pushNamed('/sign-in'),
));

final orders = await dio.get<List<dynamic>>('/orders');
```

## Notes

- **`QueuedInterceptor`, not `Interceptor`.** It serialises the interceptor
  callbacks, which is a second layer of protection alongside `_inFlight`.
- **Storage.** Use `flutter_secure_storage`; it maps to Keychain on iOS and
  EncryptedSharedPreferences on Android. Never `SharedPreferences`.
- **Proactive refresh.** Read `accessTokenExpiresAt` and renew ~60s early. Most
  401s then never happen — which matters more on a mobile network, where every
  avoided round trip is real latency.
