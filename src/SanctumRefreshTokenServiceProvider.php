<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ServiceProvider;
use Reiarseni\SanctumRefreshToken\Console\DoctorCommand;
use Reiarseni\SanctumRefreshToken\Console\ImportCommand;
use Reiarseni\SanctumRefreshToken\Console\PruneCommand;
use Reiarseni\SanctumRefreshToken\Context\ContextResolverFactory;
use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;
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

        $this->registerSchedule(new Settings($this->app->make('config')));

        $this->commands([
            PruneCommand::class,
            DoctorCommand::class,
            ImportCommand::class,
        ]);
    }

    /**
     * Register pruning on the scheduler, when asked to.
     *
     * Off by default. The package's own README promises it will not do work
     * nobody asked for, and although pruning terminal rows logs nobody out, a
     * package that starts writing to the scheduler by surprise is a package
     * that surprises someone during an incident. `sanctum-refresh:doctor`
     * reports when this being off has let the table grow.
     */
    private function registerSchedule(Settings $settings): void
    {
        $frequency = $settings->nullableString('sanctum-refresh-token.prune.schedule');

        if ($frequency === null || $frequency === '' || $frequency === 'false') {
            return;
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule) use ($frequency): void {
            $event = $schedule->command('sanctum-refresh:prune');

            if (method_exists($event, $frequency)) {
                $event->{$frequency}();
            } else {
                $event->cron($frequency);
            }

            $event->onOneServer();
        });
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

        $settings = new Settings($config);

        // A live row can never be pruned -- deleting it revokes a credential
        // somebody is still using -- so every family needs a horizon of some
        // kind, or the table grows without any bound an operator can fix.
        if ($settings->nullableInt('sanctum-refresh-token.expiration.refresh_token') === null
            && $settings->nullableInt('sanctum-refresh-token.expiration.family') === null) {
            throw ConfigurationException::noLifetimeHorizon();
        }

        TokenSecret::assertSafeLength(
            $settings->int(
                'sanctum-refresh-token.security.secret_bytes',
                TokenSecret::MINIMUM_BYTES,
                // No floor: a value below the minimum has to reach the check
                // and be refused, not be quietly raised to a safe one.
                PHP_INT_MIN,
            ),
        );
    }
}
