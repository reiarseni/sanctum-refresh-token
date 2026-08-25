<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Reiarseni\SanctumRefreshToken\Models\RefreshToken;

/**
 * The package's only application-wide state: the refresh token model, and an
 * optional context resolver registered as a closure. Everything else is
 * resolved per request out of the container.
 */
final class SanctumRefreshToken
{
    /** @var class-string<RefreshToken>|null */
    private static ?string $refreshTokenModel = null;

    /** @var (Closure(): (string|int|null))|null */
    private static ?Closure $contextResolver = null;

    /**
     * @param  class-string<RefreshToken>  $model
     */
    public static function useRefreshTokenModel(string $model): void
    {
        self::$refreshTokenModel = $model;
    }

    /**
     * @return class-string<RefreshToken>
     */
    public static function refreshTokenModel(): string
    {
        if (self::$refreshTokenModel !== null) {
            return self::$refreshTokenModel;
        }

        $configured = config('sanctum-refresh-token.model', RefreshToken::class);

        if (is_string($configured) && is_a($configured, RefreshToken::class, true)) {
            return $configured;
        }

        return RefreshToken::class;
    }

    public static function newRefreshToken(): RefreshToken
    {
        $model = self::refreshTokenModel();

        return new $model;
    }

    /**
     * @return Builder<RefreshToken>
     */
    public static function query()
    {
        return self::newRefreshToken()->newQuery();
    }

    /**
     * Takes precedence over the resolver named in the configuration file.
     *
     * @param  (Closure(): (string|int|null))|null  $callback
     */
    public static function resolveContextUsing(?Closure $callback): void
    {
        self::$contextResolver = $callback;
    }

    /**
     * @return (Closure(): (string|int|null))|null
     */
    public static function contextResolverCallback(): ?Closure
    {
        return self::$contextResolver;
    }

    /**
     * Test helper; harmless in production.
     */
    public static function flushState(): void
    {
        self::$refreshTokenModel = null;
        self::$contextResolver = null;
    }

    public static function isTokenable(Model $model): bool
    {
        return method_exists($model, 'createToken') && method_exists($model, 'tokens');
    }
}
