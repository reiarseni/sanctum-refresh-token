<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use RuntimeException;

/**
 * Rotation is all-or-nothing.
 *
 * This lives apart from the rest of the rotation tests because it is the one
 * assertion that cannot be made from inside a test transaction: the usual
 * RefreshDatabase wrapper turns the package's own transaction into a savepoint,
 * and a savepoint rollback is not the thing under test -- MySQL will not even
 * let one be rolled back reliably after a failure inside it. So this test
 * commits its setup and cleans up after itself, and the transaction it exercises
 * is a real one.
 */
#[Group('migrations')]
final class RotationAtomicityTest extends TestCase
{
    protected function setUp(): void
    {
        $this->needsCommittedData = true;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->truncateTokenTables();

        parent::tearDown();
    }

    #[Test]
    public function a_failure_mid_rotation_leaves_no_partial_state(): void
    {
        $manager = $this->app->make(RefreshTokenManager::class);

        $pair = $manager->issue($this->createUser());
        $accessTokenId = SanctumRefreshToken::query()->firstOrFail()->access_token_id;

        // Minting the replacement access token fails partway through the
        // rotation, after the transaction has opened.
        Event::listen(
            'eloquent.creating: '.Sanctum::personalAccessTokenModel(),
            static function (): void {
                throw new RuntimeException('minting failed');
            },
        );

        try {
            $manager->rotate($pair->refreshToken);
            $this->fail('The rotation should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('minting failed', $e->getMessage());
        }

        $consumed = SanctumRefreshToken::query()->firstOrFail();

        $this->assertNull($consumed->rotated_at, 'The consumed row must not be marked rotated.');
        $this->assertSame(1, SanctumRefreshToken::query()->count(), 'No new generation may exist.');
        $this->assertNotNull(
            Sanctum::personalAccessTokenModel()::query()->find($accessTokenId),
            'The previous access token must still authenticate.',
        );
    }
}
