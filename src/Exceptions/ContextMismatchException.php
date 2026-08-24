<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The family was issued in one context and is being rotated in another, or the
 * current context could not be resolved at all.
 *
 * An unresolvable context is a mismatch on purpose: a security control that
 * cannot establish where it is has to fail closed.
 */
final class ContextMismatchException extends SanctumRefreshTokenException
{
    private function __construct(
        string $message,
        public readonly ?string $recordedContext,
        public readonly ?string $resolvedContext,
    ) {
        parent::__construct($message);
    }

    public static function make(?string $recordedContext, ?string $resolvedContext): self
    {
        return new self(
            'The refresh token was issued in a different context to the current one.',
            $recordedContext,
            $resolvedContext,
        );
    }

    public function errorCode(): string
    {
        return 'context_mismatch';
    }
}
