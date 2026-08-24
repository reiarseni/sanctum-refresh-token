<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\SanctumRefreshTokenServiceProvider;
use Reiarseni\SanctumRefreshToken\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        SanctumRefreshToken::flushState();

        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        SanctumRefreshToken::flushState();

        parent::tearDown();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SanctumServiceProvider::class,
            SanctumRefreshTokenServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/sanctum/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * The context column ships as a separate publishable migration, so tests
     * that exercise binding add it explicitly.
     */
    protected function addContextColumn(string $column = 'context'): void
    {
        Schema::table(SanctumRefreshToken::newRefreshToken()->getTable(), function (Blueprint $table) use ($column): void {
            $table->string($column)->nullable()->index();
        });
    }

    protected function createUser(string $email = 'rei@example.com'): User
    {
        return User::query()->create([
            'name' => 'Rei',
            'email' => $email,
            'password' => 'irrelevant-for-these-tests',
        ]);
    }

    private function createUsersTable(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Whether the current connection can actually take a row-level lock.
     *
     * SQLite cannot, which is why the concurrency group skips there with an
     * explicit reason rather than passing and proving nothing.
     */
    protected function supportsRowLocking(?Application $app = null): bool
    {
        $driver = $this->app->make('db')->connection()->getDriverName();

        return in_array($driver, ['mysql', 'mariadb', 'pgsql'], true);
    }
}
