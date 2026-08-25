<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\Fixtures\User;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * The published controller, exercised as the file it is.
 *
 * `stubs/RefreshTokenController.php.stub` is what an integrator copies into
 * their application. Its job is to map the package's error codes onto HTTP
 * status codes, and one of those mappings carries real weight:
 * `rotation_in_progress` must be **409**, not 401. A client that receives 401
 * concludes the session is over and signs the user out — which is the failure
 * this whole grace window exists to prevent.
 *
 * So this test loads that file rather than reimplementing its logic inline. A
 * test that restates the mapping proves the manager works and proves nothing
 * about the stub, which is exactly the gap that lets an edit to the stub ship
 * green.
 */
final class PublishedControllerTest extends TestCase
{
    private const STUB = __DIR__.'/../../stubs/RefreshTokenController.php.stub';

    /** The class name the rewritten stub is loaded under. */
    private const LOADED = __NAMESPACE__.'\\Published\\RefreshTokenController';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum-refresh-token.rotation.reuse_grace_period' => 60,
            'sanctum-refresh-token.rotation.on_grace_replay' => 'reject',
        ]);

        $this->loadPublishedController();
        $this->registerPublishedRoutes();
    }

    #[Test]
    public function two_refreshes_with_the_same_token_produce_one_pair_and_one_conflict(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        $first = $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken]);
        $second = $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken]);

        $first->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'family', 'generation',
        ]);

        // 409, not 401. A client that reads 401 here signs the user out over a
        // retry, which is the whole failure this package is built to avoid.
        $second->assertStatus(409)->assertJson(['error' => 'rotation_in_progress']);
    }

    #[Test]
    public function the_family_survives_the_refusal_and_the_new_pair_still_rotates(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        $issued = $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken])->json();
        $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken])->assertStatus(409);

        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());

        $this->postJson('/auth/refresh', ['refresh_token' => $issued['refresh_token']])
            ->assertOk()
            ->assertJson(['generation' => 3]);
    }

    #[Test]
    public function an_unusable_token_is_an_authentication_failure(): void
    {
        $this->createUser();

        $this->postJson('/auth/refresh', ['refresh_token' => 'not-a-token'])
            ->assertStatus(401)
            ->assertJson(['error' => 'refresh_token_invalid']);

        $this->postJson('/auth/refresh', ['refresh_token' => '424242|'.str_repeat('a', 64)])
            ->assertStatus(401)
            ->assertJson(['error' => 'refresh_token_invalid']);
    }

    #[Test]
    public function a_single_flight_client_never_sees_a_conflict(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        // What every reference client under docs/clients/ does: concurrent
        // callers await one in-flight refresh instead of each starting their
        // own. The README says this is not optional; this asserts it works.
        $inFlight = null;
        $refresh = function () use (&$inFlight, $pair) {
            return $inFlight ??= $this->postJson('/auth/refresh', [
                'refresh_token' => $pair->refreshToken,
            ]);
        };

        $a = $refresh();
        $b = $refresh();

        $a->assertOk();
        $b->assertOk();
        $this->assertSame($a->json('refresh_token'), $b->json('refresh_token'));

        $this->assertSame(
            2,
            SanctumRefreshToken::query()->max('generation'),
            'One shared refresh must advance the family exactly once.',
        );
    }

    #[Test]
    public function the_tolerant_mode_answers_both_requests(): void
    {
        config(['sanctum-refresh-token.rotation.on_grace_replay' => 'reissue']);

        $pair = $this->manager()->issue($this->createUser());

        $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken])->assertOk();
        $this->postJson('/auth/refresh', ['refresh_token' => $pair->refreshToken])->assertOk();

        // No 409 reached the client at all, and the family advanced twice.
        $this->assertSame(3, SanctumRefreshToken::query()->max('generation'));
    }

    #[Test]
    public function the_login_and_session_endpoints_of_the_stub_respond(): void
    {
        $user = $this->createUser();
        $user->forceFill(['password' => bcrypt('correct-horse')])->save();

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse',
            'device_name' => "Rei's iPhone",
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->actingAs($user)
            ->getJson('/auth/sessions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'label', 'device', 'is_current']]]);
    }

    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    /**
     * Load the published stub under a namespace this test can reach.
     *
     * The stub is written for an application: it declares `App\Http\...` and
     * imports `App\Models\User` and the framework's base controller. Those are
     * aliased here so its imports resolve, and the namespace is rewritten so
     * the file can be loaded from the package's own test suite.
     */
    private function loadPublishedController(): void
    {
        if (class_exists(self::LOADED, false)) {
            return;
        }

        class_alias(User::class, 'App\\Models\\User');
        class_alias(BaseController::class, 'App\\Http\\Controllers\\Controller');

        $source = @file_get_contents(self::STUB);

        // Loudly, not quietly: a test that cannot load the stub must fail
        // rather than pass while exercising nothing.
        $this->assertIsString($source, 'The published controller stub could not be read from '.self::STUB);

        $rewritten = str_replace(
            'namespace App\\Http\\Controllers\\Auth;',
            'namespace '.__NAMESPACE__.'\\Published;',
            $source,
        );

        $this->assertNotSame(
            $source,
            $rewritten,
            'The stub no longer declares the namespace this test rewrites; update the test to match it.',
        );

        $path = tempnam(sys_get_temp_dir(), 'srt-stub-').'.php';
        file_put_contents($path, $rewritten);

        require $path;
        @unlink($path);

        $this->assertTrue(
            class_exists(self::LOADED, false),
            'The published stub loaded but did not declare the expected controller class.',
        );
    }

    /**
     * The routes the stub is published alongside, mirroring
     * routes/sanctum-refresh-token.php.
     */
    private function registerPublishedRoutes(): void
    {
        $controller = self::LOADED;

        Route::prefix('auth')->group(function () use ($controller): void {
            Route::post('login', [$controller, 'login']);
            Route::post('refresh', [$controller, 'refresh']);
            Route::middleware('auth:sanctum')->group(function () use ($controller): void {
                Route::post('logout', [$controller, 'logout']);
                Route::get('sessions', [$controller, 'sessions']);
            });
        });
    }
}
