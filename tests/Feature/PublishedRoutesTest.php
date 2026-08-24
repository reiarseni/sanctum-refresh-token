<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Exceptions\SanctumRefreshTokenException;
use Reiarseni\SanctumRefreshToken\Http\Resources\SessionResource;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshTokenServiceProvider;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * The optional HTTP surface.
 *
 * The routes ship as a stub the consumer publishes and owns, so what is tested
 * here is the contract the stub documents: that nothing is mounted until the
 * consumer asks, and that once mounted the endpoints behave as written — in
 * particular that a benign refresh race answers 409 rather than 401.
 */
final class PublishedRoutesTest extends TestCase
{
    #[Test]
    public function no_route_is_registered_by_default(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        $this->assertNotContains('auth/login', $paths);
        $this->assertNotContains('auth/refresh', $paths);
        $this->assertNotContains('auth/sessions', $paths);
    }

    #[Test]
    public function the_routes_stub_and_controller_stub_are_publishable(): void
    {
        $published = SanctumRefreshTokenServiceProvider::pathsToPublish(
            SanctumRefreshTokenServiceProvider::class,
            'sanctum-refresh-token-routes',
        );

        $this->assertNotEmpty($published);

        foreach (array_keys($published) as $source) {
            $this->assertFileExists($source);
        }

        $sources = implode(' ', array_keys($published));

        $this->assertStringContainsString('routes/sanctum-refresh-token.php', $sources);
        $this->assertStringContainsString('RefreshTokenController.php.stub', $sources);
    }

    #[Test]
    public function the_published_controller_stub_maps_the_benign_race_to_409(): void
    {
        $stub = (string) file_get_contents(__DIR__.'/../../stubs/RefreshTokenController.php.stub');

        $this->assertStringContainsString("'rotation_in_progress' => 409", $stub);
        $this->assertStringContainsString('SanctumRefreshTokenException', $stub);
    }

    #[Test]
    public function the_documented_endpoints_behave_as_the_stub_describes(): void
    {
        config(['sanctum-refresh-token.rotation.reuse_grace_period' => 60]);

        $manager = $this->app->make(RefreshTokenManager::class);
        $user = $this->createUser();

        // The stub's own routing, mounted here the way a consumer would mount
        // the file it published.
        Route::post('auth/refresh', function (Request $request) use ($manager) {
            try {
                return response()->json(
                    $manager->rotate((string) $request->input('refresh_token'))->toArray(),
                );
            } catch (SanctumRefreshTokenException $e) {
                return response()->json(['error' => $e->errorCode()], match ($e->errorCode()) {
                    'rotation_in_progress' => 409,
                    'abilities_escalation', 'context_mismatch' => 403,
                    default => 401,
                });
            }
        });

        $pair = $manager->issue($user);

        $ok = $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken]);
        $ok->assertOk()->assertJsonStructure(['access_token', 'refresh_token', 'token_type', 'family', 'generation']);

        // The retry a mobile client sends after a lost response.
        $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken])
            ->assertStatus(409)
            ->assertJson(['error' => 'rotation_in_progress']);

        $this->postJson('/auth/refresh', ['refresh_token' => 'garbage'])
            ->assertStatus(401)
            ->assertJson(['error' => 'refresh_token_invalid']);
    }

    #[Test]
    public function the_sessions_endpoint_serialises_through_the_resource(): void
    {
        $user = $this->createUser();
        $this->app->make(RefreshTokenManager::class)->issue($user);

        Route::get('auth/sessions', fn () => response()->json([
            'data' => SessionResource::collection($user->sessions()->all()),
        ]));

        $this->getJson('/auth/sessions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'label', 'device', 'is_current', 'generation']]])
            ->assertJsonMissing(['token']);
    }
}
