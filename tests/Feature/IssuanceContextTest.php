<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Tests\Feature;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Reiarseni\SanctumRefreshToken\Context\ContextResolverFactory;
use Reiarseni\SanctumRefreshToken\Context\NullContextResolver;
use Reiarseni\SanctumRefreshToken\Context\SpatieContextResolver;
use Reiarseni\SanctumRefreshToken\Context\StanclContextResolver;
use Reiarseni\SanctumRefreshToken\Contracts\ContextResolver;
use Reiarseni\SanctumRefreshToken\Enums\RevocationReason;
use Reiarseni\SanctumRefreshToken\Events\ContextMismatchDetected;
use Reiarseni\SanctumRefreshToken\Exceptions\ContextMismatchException;
use Reiarseni\SanctumRefreshToken\Exceptions\SessionNotFoundException;
use Reiarseni\SanctumRefreshToken\RefreshTokenManager;
use Reiarseni\SanctumRefreshToken\SanctumRefreshToken;
use Reiarseni\SanctumRefreshToken\Tests\TestCase;

/**
 * Cross-context isolation.
 *
 * The fixture User carries no global scope of any kind, on purpose: every
 * assertion here has to hold because the package compares two values, not
 * because Eloquent filtered a query.
 */
final class IssuanceContextTest extends TestCase
{
    private ?string $context = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addContextColumn();

        config([
            'sanctum-refresh-token.context.enabled' => true,
            'sanctum-refresh-token.context.on_mismatch' => 'reject',
        ]);

        SanctumRefreshToken::resolveContextUsing(fn (): ?string => $this->context);
    }

    private function manager(): RefreshTokenManager
    {
        return $this->app->make(RefreshTokenManager::class);
    }

    private function inContext(?string $context): self
    {
        $this->context = $context;

        return $this;
    }

    #[Test]
    public function the_resolved_context_is_recorded_at_issuance(): void
    {
        $this->inContext('ACME')->manager()->issue($this->createUser());

        $this->assertSame('ACME', SanctumRefreshToken::query()->firstOrFail()->getAttribute('context'));
    }

    #[Test]
    public function the_context_is_carried_forward_on_rotation(): void
    {
        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        $this->manager()->rotate($pair->refreshToken);

        $this->assertSame(
            'ACME',
            SanctumRefreshToken::query()->where('generation', 2)->firstOrFail()->getAttribute('context'),
        );
    }

    #[Test]
    public function the_context_is_carried_forward_even_when_the_current_one_changed(): void
    {
        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        // The resolver has moved on, but rotation copies the recorded value
        // rather than re-resolving it — otherwise a reassigned user would drag
        // an old family into a new context.
        config(['sanctum-refresh-token.context.enabled' => false]);
        $this->manager()->rotate($pair->refreshToken);

        $this->assertSame(
            'ACME',
            SanctumRefreshToken::query()->where('generation', 2)->firstOrFail()->getAttribute('context'),
        );
    }

    #[Test]
    public function disabled_binding_records_nothing_and_invokes_no_resolver(): void
    {
        config(['sanctum-refresh-token.context.enabled' => false]);

        $invoked = false;
        SanctumRefreshToken::resolveContextUsing(function () use (&$invoked): ?string {
            $invoked = true;

            return 'ACME';
        });

        $this->manager()->issue($this->createUser());

        $this->assertFalse($invoked);
        $this->assertNull(SanctumRefreshToken::query()->firstOrFail()->getAttribute('context'));
    }

    #[Test]
    public function the_column_name_is_configurable(): void
    {
        config(['sanctum-refresh-token.context.column' => 'tenant_code']);
        $this->addContextColumn('tenant_code');

        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        $this->assertSame('ACME', SanctumRefreshToken::query()->firstOrFail()->getAttribute('tenant_code'));

        $this->manager()->rotate($pair->refreshToken);

        $this->assertSame(
            'ACME',
            SanctumRefreshToken::query()->where('generation', 2)->firstOrFail()->getAttribute('tenant_code'),
        );
    }

    #[Test]
    public function a_matching_context_permits_rotation(): void
    {
        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        $rotated = $this->inContext('ACME')->manager()->rotate($pair->refreshToken);

        $this->assertSame(2, $rotated->generation);
    }

    #[Test]
    public function a_mismatched_context_refuses_rotation_without_revoking(): void
    {
        Event::fake([ContextMismatchDetected::class]);

        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        $exception = $this->assertRefused($pair->refreshToken, 'GLOBEX');

        $this->assertSame('ACME', $exception->recordedContext);
        $this->assertSame('GLOBEX', $exception->resolvedContext);
        $this->assertSame(1, SanctumRefreshToken::query()->max('generation'));
        $this->assertSame(0, SanctumRefreshToken::query()->whereNotNull('revoked_at')->count());

        // And the family is still perfectly usable from where it belongs.
        $this->assertSame(2, $this->inContext('ACME')->manager()->rotate($pair->refreshToken)->generation);

        Event::assertDispatched(
            ContextMismatchDetected::class,
            static fn (ContextMismatchDetected $e): bool => $e->recordedContext === 'ACME'
                && $e->resolvedContext === 'GLOBEX',
        );
    }

    #[Test]
    public function isolation_does_not_depend_on_a_global_scope(): void
    {
        $user = $this->createUser();

        // Stated as an assertion rather than left implicit: if anything ever
        // adds a global scope to either model, this test stops proving what it
        // claims to prove, and it should fail rather than pass for the wrong
        // reason.
        $this->assertSame([], SanctumRefreshToken::newRefreshToken()->getGlobalScopes());
        $this->assertSame([], $user->getGlobalScopes());

        $pair = $this->inContext('ACME')->manager()->issue($user);

        $exception = $this->assertRefused($pair->refreshToken, 'GLOBEX');

        $this->assertSame('context_mismatch', $exception->errorCode());
    }

    #[Test]
    public function an_unresolvable_context_refuses_rather_than_allowing_rotation(): void
    {
        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        // The control cannot establish where it is, so it fails closed.
        $exception = $this->assertRefused($pair->refreshToken, null);

        $this->assertNull($exception->resolvedContext);
    }

    #[Test]
    public function an_unbound_family_is_unaffected_by_the_current_context(): void
    {
        config(['sanctum-refresh-token.context.enabled' => false]);
        $pair = $this->manager()->issue($this->createUser());

        config(['sanctum-refresh-token.context.enabled' => true]);

        $this->assertSame(2, $this->inContext('GLOBEX')->manager()->rotate($pair->refreshToken)->generation);
    }

    #[Test]
    public function the_strict_setting_revokes_on_mismatch(): void
    {
        config(['sanctum-refresh-token.context.on_mismatch' => 'revoke_family']);

        $pair = $this->inContext('ACME')->manager()->issue($this->createUser());

        $this->assertRefused($pair->refreshToken, 'GLOBEX');

        $row = SanctumRefreshToken::query()->firstOrFail();

        $this->assertNotNull($row->revoked_at);
        $this->assertSame(RevocationReason::ContextMismatch, $row->revocation_reason);
        $this->assertNotSame(RevocationReason::ReuseDetected, $row->revocation_reason);
    }

    #[Test]
    public function a_family_from_one_context_cannot_be_rotated_from_another(): void
    {
        $user = $this->createUser();

        $acme = $this->inContext('ACME')->manager()->issue($user);
        $globex = $this->inContext('GLOBEX')->manager()->issue($user);

        $this->assertRefused($acme->refreshToken, 'GLOBEX');
        $this->assertRefused($globex->refreshToken, 'ACME');

        $this->assertSame(2, $this->inContext('ACME')->manager()->rotate($acme->refreshToken)->generation);
        $this->assertSame(2, $this->inContext('GLOBEX')->manager()->rotate($globex->refreshToken)->generation);
    }

    #[Test]
    public function sessions_of_one_context_are_not_listed_in_another(): void
    {
        $user = $this->createUser();

        $acme = $this->inContext('ACME')->manager()->issue($user);
        $globex = $this->inContext('GLOBEX')->manager()->issue($user);

        $this->inContext('GLOBEX');
        $listed = $user->sessions()->all();

        $this->assertCount(1, $listed);
        $this->assertSame($globex->familyUuid, $listed->first()->familyUuid);

        $this->inContext('ACME');
        $listed = $user->sessions()->all();

        $this->assertCount(1, $listed);
        $this->assertSame($acme->familyUuid, $listed->first()->familyUuid);
    }

    #[Test]
    public function a_family_from_one_context_cannot_be_revoked_from_another(): void
    {
        $user = $this->createUser();

        $acme = $this->inContext('ACME')->manager()->issue($user);

        $this->inContext('GLOBEX');

        try {
            $user->sessions()->revoke($acme->familyUuid);
            $this->fail('Revoking another context\'s session should have been refused.');
        } catch (SessionNotFoundException $e) {
            $this->assertSame('session_not_found', $e->errorCode());
        }

        $this->assertNull(SanctumRefreshToken::query()->firstOrFail()->revoked_at);
    }

    #[Test]
    public function a_closure_resolver_is_honoured(): void
    {
        SanctumRefreshToken::resolveContextUsing(static fn (): string => 'FIXED');

        $this->manager()->issue($this->createUser());

        $this->assertSame('FIXED', SanctumRefreshToken::query()->firstOrFail()->getAttribute('context'));
    }

    #[Test]
    public function a_custom_resolver_class_is_honoured(): void
    {
        SanctumRefreshToken::resolveContextUsing(null);
        config(['sanctum-refresh-token.context.resolver' => FixedContextResolver::class]);

        $this->manager()->issue($this->createUser());

        $this->assertSame('FROM-CLASS', SanctumRefreshToken::query()->firstOrFail()->getAttribute('context'));
    }

    #[Test]
    public function the_package_boots_with_neither_third_party_tenancy_package_installed(): void
    {
        SanctumRefreshToken::resolveContextUsing(null);
        config(['sanctum-refresh-token.context.resolver' => null]);

        $this->assertFalse(StanclContextResolver::isAvailable());
        $this->assertFalse(SpatieContextResolver::isAvailable());

        $resolver = $this->app->make(ContextResolverFactory::class)->make();

        $this->assertInstanceOf(NullContextResolver::class, $resolver);
        $this->assertNull($resolver->resolve());
    }

    private function assertRefused(string $token, ?string $context): ContextMismatchException
    {
        $this->inContext($context);

        try {
            $this->manager()->rotate($token);
        } catch (ContextMismatchException $e) {
            $this->assertSame('context_mismatch', $e->errorCode());

            return $e;
        }

        $this->fail('The rotation should have been refused for a context mismatch.');
    }
}

final class FixedContextResolver implements ContextResolver
{
    public function resolve(): ?string
    {
        return 'FROM-CLASS';
    }
}
