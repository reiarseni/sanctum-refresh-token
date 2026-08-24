<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\SanctumRefreshTokenServiceProvider;
use Reiarseni\SanctumRefreshToken\Support\MetadataHasher;
use Reiarseni\SanctumRefreshToken\Support\TokenSecret;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;
use SplFileInfo;

final class TokenSecurityTest extends TestCase
{
    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    #[Test]
    public function storage_holds_a_hash_and_never_the_secret(): void
    {
        $pair = $this->manager()->issue($this->createUser());

        [, $secret] = explode('|', $pair->refreshToken, 2);
        $row = SanctumRefreshToken::query()->firstOrFail();

        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertNotSame($secret, $row->token);
    }

    #[Test]
    public function the_default_secret_carries_at_least_256_bits_of_entropy(): void
    {
        $this->assertSame(32, TokenSecret::MINIMUM_BYTES);
        $this->assertSame(32, config('sanctum-refresh-token.security.secret_bytes'));

        $pair = $this->manager()->issue($this->createUser());
        [, $secret] = explode('|', $pair->refreshToken, 2);

        // Hex-encoded, so two characters per byte.
        $this->assertSame(64, strlen($secret));
    }

    #[Test]
    public function generated_secrets_do_not_repeat(): void
    {
        $secrets = [];

        for ($i = 0; $i < 500; $i++) {
            $secrets[] = TokenSecret::generate(32);
        }

        $this->assertCount(500, array_unique($secrets));
    }

    #[Test]
    public function a_secret_length_below_the_minimum_is_refused_at_boot(): void
    {
        $this->assertBootRefuses(
            ['sanctum-refresh-token.security.secret_bytes' => 8],
            '/below the package minimum/',
        );
    }

    #[Test]
    public function an_unsafe_configured_identifier_is_refused_at_boot(): void
    {
        $this->assertBootRefuses(
            ['sanctum-refresh-token.table' => 'refresh_tokens; drop table users'],
            '/not a safe SQL identifier/',
        );
    }

    #[Test]
    public function an_unsafe_context_column_is_refused_at_boot(): void
    {
        $this->assertBootRefuses(
            ['sanctum-refresh-token.context.column' => 'tenant"; --'],
            '/not a safe SQL identifier/',
        );
    }

    /**
     * Boot the provider under a bad configuration and assert it refuses.
     *
     * The configuration is restored afterwards: leaving an unsafe table name in
     * place would break the migration rollback in tearDown, and the failure
     * would then look like a bug in the package rather than in this test.
     *
     * @param  array<string, mixed>  $configuration
     */
    private function assertBootRefuses(array $configuration, string $messagePattern): void
    {
        $original = [];

        foreach (array_keys($configuration) as $key) {
            $original[$key] = config($key);
        }

        config($configuration);

        try {
            (new SanctumRefreshTokenServiceProvider($this->app))->boot();
            $this->fail('The provider should have refused to boot.');
        } catch (ConfigurationException $e) {
            $this->assertSame('invalid_configuration', $e->errorCode());
            $this->assertMatchesRegularExpression($messagePattern, $e->getMessage());
        } finally {
            config($original);
        }
    }

    #[Test]
    public function verification_is_timing_safe_and_rejects_a_wrong_secret(): void
    {
        $secret = TokenSecret::generate(32);
        $hash = TokenSecret::hash($secret);

        $this->assertTrue(TokenSecret::verify($secret, $hash));
        $this->assertFalse(TokenSecret::verify(TokenSecret::generate(32), $hash));

        // The comparison itself is hash_equals, asserted against the source so
        // that a future refactor to `===` fails here rather than silently.
        $source = file_get_contents(__DIR__.'/../../src/Support/TokenSecret.php');

        $this->assertStringContainsString('hash_equals(', (string) $source);
    }

    #[Test]
    public function a_malformed_token_is_rejected_without_a_secret_verification(): void
    {
        $this->assertNull(TokenSecret::parse('no-separator'));
        $this->assertNull(TokenSecret::parse('|secret'));
        $this->assertNull(TokenSecret::parse('12|'));
        $this->assertNull(TokenSecret::parse('not-numeric|secret'));

        $this->assertSame(['12', 'secret'], TokenSecret::parse('12|secret'));
    }

    #[Test]
    public function the_token_column_is_hidden_from_serialisation(): void
    {
        $this->manager()->issue($this->createUser());

        $serialised = SanctumRefreshToken::query()->firstOrFail()->toArray();

        $this->assertArrayNotHasKey('token', $serialised);
        $this->assertArrayNotHasKey('ip_hash', $serialised);
        $this->assertArrayNotHasKey('user_agent_hash', $serialised);
    }

    #[Test]
    public function metadata_hashing_is_keyed_not_a_bare_digest(): void
    {
        $address = '203.0.113.7';

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $first = $this->app->make(MetadataHasher::class)->hash($address);

        // Resolved fresh so that the new key is the one it reads.
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        $second = $this->app->make(MetadataHasher::class)->hash($address);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(hash('sha256', $address), $first);
    }

    #[Test]
    public function a_metadata_change_is_still_detectable(): void
    {
        $hasher = $this->app->make(MetadataHasher::class);

        $this->assertNotSame($hasher->hash('203.0.113.7'), $hasher->hash('203.0.113.8'));
        $this->assertSame($hasher->hash('203.0.113.7'), $hasher->hash('203.0.113.7'));
    }

    #[Test]
    public function no_query_interpolates_a_runtime_value_into_raw_sql(): void
    {
        $offenders = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src'),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Interpolation is only a problem inside a raw SQL string; the one
            // selectRaw the package issues is a constant.
            if (preg_match('/(whereRaw|selectRaw|orderByRaw|DB::raw)\s*\(\s*["\'][^"\']*\$/', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
