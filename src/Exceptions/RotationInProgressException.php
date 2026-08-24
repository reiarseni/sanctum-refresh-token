<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Exceptions;

/**
 * The presented refresh token was rotated moments ago, within the configured
 * grace window: a benign retry, not a replay.
 *
 * The family is untouched. A client seeing this code should wait for the
 * in-flight refresh it already started and use its result; the single-flight
 * reference clients under docs/clients/ do exactly that.
 */
final class RotationInProgressException extends SanctumRefreshTokenException
{
    private function __construct(
        string $message,
        public readonly string $familyUuid,
        public readonly float $secondsSinceRotation,
    ) {
        parent::__construct($message);
    }

    public static function make(string $familyUuid, float $secondsSinceRotation): self
    {
        return new self(
            'This refresh token was already rotated moments ago; a rotation is in progress.',
            $familyUuid,
            $secondsSinceRotation,
        );
    }

    public function errorCode(): string
    {
        return 'rotation_in_progress';
    }
}
