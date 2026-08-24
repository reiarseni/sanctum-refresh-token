<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * A consumed refresh token was replayed outside the grace window.
 *
 * Under the default strategy the family is already revoked by the time this is
 * raised.
 */
final class RefreshTokenReusedException extends SanctumRefreshTokenException
{
    private function __construct(
        string $message,
        public readonly string $familyUuid,
        public readonly int $replayedGeneration,
        public readonly int $currentGeneration,
    ) {
        parent::__construct($message);
    }

    public static function make(string $familyUuid, int $replayedGeneration, int $currentGeneration): self
    {
        return new self(
            'A consumed refresh token was replayed; the token family is compromised.',
            $familyUuid,
            $replayedGeneration,
            $currentGeneration,
        );
    }

    public function errorCode(): string
    {
        return 'refresh_token_reused';
    }
}
