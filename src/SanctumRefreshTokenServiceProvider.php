<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Reiarseni\SanctumRefreshToken\Console\DoctorCommand;
use Reiarseni\SanctumRefreshToken\Console\ImportCommand;
use Reiarseni\SanctumRefreshToken\Console\PruneCommand;
use Reiarseni\SanctumRefreshToken\Context\ContextResolverFactory;
use Reiarseni\SanctumRefreshToken\Sessions\SessionManager;
use Reiarseni\SanctumRefreshToken\Support\Identifier;
use Reiarseni\SanctumRefreshToken\Support\MetadataHasher;
use Reiarseni\SanctumRefreshToken\Support\Settings;
use Reiarseni\SanctumRefreshToken\Support\TokenSecret;

class SanctumRefreshTokenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sanctum-refresh-token.php', 'sanctum-refresh-token');

        $this->app->singleton(ContextResolverFactory::class);
        $this->app->singleton(MetadataHasher::class);
        $this->app->singleton(RefreshTokenManager::class);
        $this->app->singleton(SessionManager::class);
    }

    public function boot(): void
    {
        // Configuration that would weaken tokens or reach SQL unescaped fails
        // here, at boot, rather than at the first refresh in production.
        $this->assertConfigurationIsSafe($this->app->make('config'));

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/sanctum-refresh-token.php' => config_path('sanctum-refresh-token.php'),
        ], 'sanctum-refresh-token-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'sanctum-refresh-token-migrations');

        // The issuance-context column is published separately: an application
        // that does not bind families to a context has no use for it.
        $this->publishes([
            __DIR__.'/../database/context-migrations' => database_path('migrations'),
        ], 'sanctum-refresh-token-context-migration');

        // Routes ship as a stub the application owns, not as registered routes:
        // a token endpoint is not something a package should mount by surprise.
        $this->publishes([
            __DIR__.'/../routes/sanctum-refresh-token.php' => base_path('routes/sanctum-refresh-token.php'),
            __DIR__.'/../stubs/RefreshTokenController.php.stub' => app_path('Http/Controllers/Auth/RefreshTokenController.php'),
        ], 'sanctum-refresh-token-routes');

        $this->commands([
            PruneCommand::class,
            DoctorCommand::class,
            ImportCommand::class,
        ]);
    }

    /**
     * Refuse to boot under configuration the package cannot honour safely.
     */
    private function assertConfigurationIsSafe(Config $config): void
    {
        $table = $config->get('sanctum-refresh-token.table', 'refresh_tokens');
        Identifier::assertSafe(is_string($table) ? $table : '', 'sanctum-refresh-token.table');

        $column = $config->get('sanctum-refresh-token.context.column', 'context');
        Identifier::assertSafe(is_string($column) ? $column : '', 'sanctum-refresh-token.context.column');

        TokenSecret::assertSafeLength(
            (new Settings($config))->int(
                'sanctum-refresh-token.security.secret_bytes',
                TokenSecret::MINIMUM_BYTES,
                // No floor: a value below the minimum has to reach the check
                // and be refused, not be quietly raised to a safe one.
                PHP_INT_MIN,
            ),
        );
    }
}
