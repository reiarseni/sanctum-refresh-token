<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Error;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Exceptions\InvalidSessionLabelException;
use Reiarseni\SanctumRefreshToken\Exceptions\SessionNotFoundException;
use Reiarseni\SanctumRefreshToken\Http\Resources\SessionResource;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\Fixtures\User;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use Reiarseni\SanctumRefreshToken\ValueObjects\Session;
use Reiarseni\SanctumRefreshToken\ValueObjects\TokenConfig;

final class SessionManagementTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function listing_returns_one_entry_per_live_family(): void
    {
        $user = $this->createUser();

        $this->manager()->issue($user);
        $this->manager()->issue($user);
        $this->manager()->issue($user);
        $revoked = $this->manager()->issue($user);

        $this->manager()->revokeFamily($revoked->familyUuid);

        $sessions = $user->sessions()->all();

        $this->assertCount(3, $sessions);
        $this->assertNotContains($revoked->familyUuid, $sessions->pluck('familyUuid')->all());
    }

    #[Test]
    public function a_rotated_family_is_still_one_session_at_its_new_generation(): void
    {
        $user = $this->createUser();
        $pair = $this->manager()->issue($user);

        $this->manager()->rotate($pair->refreshToken);

        $sessions = $user->sessions()->all();

        $this->assertCount(1, $sessions);
        $this->assertSame(2, $sessions->first()->generation);
    }

    #[Test]
    public function a_session_object_exposes_the_documented_shape(): void
    {
        $user = $this->createUser();
        $this->manager()->issue($user, TokenConfig::make()->withName('Rei\'s iPhone'));

        $session = $user->sessions()->all()->first();

        $this->assertInstanceOf(Session::class, $session);
        $this->assertNotSame('', $session->familyUuid);
        $this->assertSame('Rei\'s iPhone', $session->label);
        $this->assertFalse($session->isCurrent);
        $this->assertSame(1, $session->generation);
        $this->assertNotNull($session->createdAt);
        $this->assertNotNull($session->lastUsedAt);
        $this->assertNotNull($session->expiresAt);
        $this->assertFalse($session->device->isAvailable());
    }

    #[Test]
    public function session_objects_reject_mutation(): void
    {
        $user = $this->createUser();
        $this->manager()->issue($user);

        $session = $user->sessions()->all()->first();

        $this->expectException(Error::class);

        // @phpstan-ignore-next-line intentionally illegal: readonly property
        $session->label = 'Tampered';
    }

    #[Test]
    public function sessions_are_ordered_by_recency_of_use(): void
    {
        $user = $this->createUser();

        // Relative to now, so that every family is still live when listed.
        Carbon::setTestNow(Carbon::now()->subHours(3));
        $oldest = $this->manager()->issue($user);

        Carbon::setTestNow(Carbon::now()->addHour());
        $middle = $this->manager()->issue($user);

        Carbon::setTestNow(Carbon::now()->addHour());
        $newest = $this->manager()->issue($user);

        Carbon::setTestNow();

        $this->assertSame(
            [$newest->familyUuid, $middle->familyUuid, $oldest->familyUuid],
            $user->sessions()->all()->pluck('familyUuid')->all(),
        );
    }

    #[Test]
    public function the_session_of_the_authenticating_token_is_flagged_current(): void
    {
        $user = $this->createUser();

        $current = $this->manager()->issue($user);
        $other = $this->manager()->issue($user);

        $authenticated = $this->actingAsFamily($user, $current->familyUuid);

        $sessions = $authenticated->sessions()->all();

        $this->assertCount(1, $sessions->where('isCurrent', true));
        $this->assertSame($current->familyUuid, $sessions->firstWhere('isCurrent', true)->familyUuid);
        $this->assertFalse($sessions->firstWhere('familyUuid', $other->familyUuid)->isCurrent);
        $this->assertSame($current->familyUuid, $authenticated->sessions()->current()->familyUuid);
    }

    #[Test]
    public function an_unauthenticated_context_flags_no_session_as_current(): void
    {
        $user = $this->createUser();
        $this->manager()->issue($user);

        $sessions = $user->sessions()->all();

        $this->assertCount(0, $sessions->where('isCurrent', true));
        $this->assertNull($user->sessions()->current());
    }

    #[Test]
    public function a_missing_label_falls_back_to_the_configured_default(): void
    {
        config(['sanctum-refresh-token.session.default_label' => 'Unknown device']);

        $user = $this->createUser();
        $this->manager()->issue($user);

        $this->assertSame('Unknown device', $user->sessions()->all()->first()->label);
    }

    #[Test]
    public function a_session_can_be_renamed(): void
    {
        $user = $this->createUser();
        $pair = $this->manager()->issue($user, TokenConfig::make()->withName('Old name'));

        $renamed = $user->sessions()->rename($pair->familyUuid, 'Work laptop');

        $this->assertSame('Work laptop', $renamed->label);
        $this->assertSame($pair->familyUuid, $renamed->familyUuid);
        $this->assertSame(1, $renamed->generation);
        $this->assertSame('Work laptop', $user->sessions()->all()->first()->label);
    }

    #[Test]
    public function a_rename_survives_a_later_rotation(): void
    {
        $user = $this->createUser();
        $pair = $this->manager()->issue($user);

        $user->sessions()->rename($pair->familyUuid, 'Work laptop');
        $this->manager()->rotate($pair->refreshToken);

        $this->assertSame('Work laptop', $user->sessions()->all()->first()->label);
    }

    #[Test]
    public function an_invalid_label_is_rejected(): void
    {
        config(['sanctum-refresh-token.session.max_label_length' => 10]);

        $user = $this->createUser();
        $pair = $this->manager()->issue($user, TokenConfig::make()->withName('Fine'));

        foreach (['a much longer label than allowed', "control\x00character"] as $invalid) {
            try {
                $user->sessions()->rename($pair->familyUuid, $invalid);
                $this->fail('The label should have been rejected.');
            } catch (InvalidSessionLabelException $e) {
                $this->assertSame('invalid_session_label', $e->errorCode());
            }
        }

        $this->assertSame('Fine', $user->sessions()->all()->first()->label);
    }

    #[Test]
    public function an_invalid_label_is_rejected_at_issuance_too(): void
    {
        $this->expectException(InvalidSessionLabelException::class);

        $this->manager()->issue($this->createUser(), TokenConfig::make()->withName("bad\x07label"));
    }

    #[Test]
    public function revoking_one_session_leaves_the_others_untouched(): void
    {
        $user = $this->createUser();

        $doomed = $this->manager()->issue($user);
        $keptA = $this->manager()->issue($user);
        $keptB = $this->manager()->issue($user);

        $user->sessions()->revoke($doomed->familyUuid);

        $remaining = $user->sessions()->all()->pluck('familyUuid')->all();

        $this->assertEqualsCanonicalizing([$keptA->familyUuid, $keptB->familyUuid], $remaining);
        $this->assertSame(2, $this->manager()->rotate($keptA->refreshToken)->generation);
    }

    #[Test]
    public function revoking_all_sessions_logs_the_tokenable_out_everywhere(): void
    {
        $user = $this->createUser();

        $this->manager()->issue($user);
        $this->manager()->issue($user);

        $revoked = $user->sessions()->revokeAll();

        $this->assertSame(2, $revoked);
        $this->assertCount(0, $user->sessions()->all());
        $this->assertSame(0, Sanctum::personalAccessTokenModel()::query()->count());
    }

    #[Test]
    public function revoking_other_sessions_preserves_the_current_one(): void
    {
        $user = $this->createUser();

        $current = $this->manager()->issue($user);
        $this->manager()->issue($user);
        $this->manager()->issue($user);

        $authenticated = $this->actingAsFamily($user, $current->familyUuid);

        $this->assertSame(2, $authenticated->sessions()->revokeOthers());

        $remaining = $authenticated->sessions()->all();

        $this->assertCount(1, $remaining);
        $this->assertSame($current->familyUuid, $remaining->first()->familyUuid);
        $this->assertSame(2, $this->manager()->rotate($current->refreshToken)->generation);
    }

    #[Test]
    public function revoking_a_session_that_is_not_the_tokenables_is_refused(): void
    {
        $owner = $this->createUser('owner@example.com');
        $stranger = $this->createUser('stranger@example.com');

        $pair = $this->manager()->issue($owner);

        try {
            $stranger->sessions()->revoke($pair->familyUuid);
            $this->fail('Revoking a foreign family should have been refused.');
        } catch (SessionNotFoundException $e) {
            $this->assertSame('session_not_found', $e->errorCode());
        }

        $this->assertNull(SanctumRefreshToken::query()->firstOrFail()->revoked_at);
    }

    #[Test]
    public function metadata_is_hashed_by_default_and_plaintext_is_opt_in(): void
    {
        $user = $this->createUser();

        $this->withRequest('203.0.113.7', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/605.1');

        $this->manager()->issue($user);

        $row = SanctumRefreshToken::query()->firstOrFail();

        $this->assertNotNull($row->ip_hash);
        $this->assertNotSame('203.0.113.7', $row->ip_hash);
        $this->assertStringNotContainsString('iPhone', (string) $row->user_agent_hash);
        $this->assertFalse($user->sessions()->all()->first()->device->isAvailable());

        // Opt in, and the same inputs become readable.
        config(['sanctum-refresh-token.security.store_metadata_plaintext' => true]);

        $second = $this->createUser('second@example.com');
        $this->manager()->issue($second);

        $device = $second->sessions()->all()->first()->device;

        $this->assertTrue($device->isAvailable());
        $this->assertSame('203.0.113.7', $device->ipAddress);
        $this->assertSame('mobile', $device->platform);
        $this->assertSame('iOS', $device->operatingSystem);
    }

    #[Test]
    public function a_changed_address_produces_a_different_hash(): void
    {
        $user = $this->createUser();

        $this->withRequest('203.0.113.7', 'Agent/1.0');
        $this->manager()->issue($user);

        $this->withRequest('198.51.100.4', 'Agent/1.0');
        $this->manager()->issue($user);

        $hashes = SanctumRefreshToken::query()->pluck('ip_hash')->all();

        $this->assertCount(2, array_unique($hashes));
    }

    #[Test]
    public function absent_metadata_does_not_break_issuance(): void
    {
        $user = $this->createUser();

        // No IP and no user agent: a queued job or a console command.
        $this->withRequest(null, null);

        $pair = $this->manager()->issue($user);

        $this->assertSame(1, $pair->generation);

        $row = SanctumRefreshToken::query()->firstOrFail();

        $this->assertNull($row->ip_hash);
        $this->assertNull($row->user_agent_hash);
        $this->assertFalse($user->sessions()->all()->first()->device->isAvailable());
    }

    #[Test]
    public function the_resource_emits_the_documented_fields_and_leaks_nothing(): void
    {
        $user = $this->createUser();
        $this->manager()->issue($user, TokenConfig::make()->withName('Work laptop'));

        $session = $user->sessions()->all()->first();
        $row = SanctumRefreshToken::query()->firstOrFail();

        $payload = SessionResource::make($session)->toArray(Request::create('/sessions'));

        $this->assertSame($session->familyUuid, $payload['id']);
        $this->assertSame('Work laptop', $payload['label']);
        $this->assertArrayHasKey('device', $payload);
        $this->assertArrayHasKey('is_current', $payload);
        $this->assertArrayHasKey('created_at', $payload);
        $this->assertArrayHasKey('last_used_at', $payload);

        $encoded = json_encode($payload);

        $this->assertStringNotContainsString($row->token, (string) $encoded);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('ip_hash', $payload);
        $this->assertArrayNotHasKey('user_agent_hash', $payload);
        $this->assertArrayNotHasKey('tokenable_id', $payload);
        $this->assertStringNotContainsString('"'.$row->getKey().'"', (string) $encoded);
    }

    /**
     * Authenticate as the access token belonging to a given family.
     */
    private function actingAsFamily(User $user, string $familyUuid): User
    {
        $accessTokenId = SanctumRefreshToken::query()
            ->where('family_uuid', $familyUuid)
            ->firstOrFail()
            ->access_token_id;

        $accessToken = Sanctum::personalAccessTokenModel()::query()->findOrFail($accessTokenId);

        $user->withAccessToken($accessToken);

        return $user;
    }

    private function withRequest(?string $ip, ?string $userAgent): void
    {
        $server = [];

        if ($userAgent !== null) {
            $server['HTTP_USER_AGENT'] = $userAgent;
        }

        $request = Request::create('/auth/login', 'POST', [], [], [], $server);

        // Request::create always supplies a loopback address and a "Symfony"
        // user agent. Removing both is what issuance from a queued job or a
        // console command actually looks like.
        if ($ip === null) {
            $request->server->remove('REMOTE_ADDR');
        } else {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        if ($userAgent === null) {
            $request->server->remove('HTTP_USER_AGENT');
            $request->headers->remove('User-Agent');
        }

        $this->app->instance('request', $request);
    }
}
