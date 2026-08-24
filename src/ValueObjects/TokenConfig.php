<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\ValueObjects;

use DateTimeInterface;

/**
 * Per-issuance overrides.
 *
 * Every field is optional; anything left null falls back to the published
 * configuration. Instances are immutable — the withers return copies — so a
 * config assembled in a controller cannot be mutated by the manager it is
 * handed to.
 */
final class TokenConfig
{
    /**
     * @param  list<string>|null  $abilities
     */
    private function __construct(
        public readonly ?string $name = null,
        public readonly ?array $abilities = null,
        public readonly ?DateTimeInterface $accessTokenExpiresAt = null,
        public readonly ?DateTimeInterface $refreshTokenExpiresAt = null,
        public readonly ?DateTimeInterface $familyExpiresAt = null,
    ) {}

    public static function make(): self
    {
        return new self;
    }

    /**
     * The session label this family is listed under.
     */
    public function withName(?string $name): self
    {
        return new self($name, $this->abilities, $this->accessTokenExpiresAt, $this->refreshTokenExpiresAt, $this->familyExpiresAt);
    }

    /**
     * @param  list<string>|null  $abilities
     */
    public function withAbilities(?array $abilities): self
    {
        return new self($this->name, $abilities, $this->accessTokenExpiresAt, $this->refreshTokenExpiresAt, $this->familyExpiresAt);
    }

    public function withAccessTokenExpiresAt(?DateTimeInterface $expiresAt): self
    {
        return new self($this->name, $this->abilities, $expiresAt, $this->refreshTokenExpiresAt, $this->familyExpiresAt);
    }

    public function withRefreshTokenExpiresAt(?DateTimeInterface $expiresAt): self
    {
        return new self($this->name, $this->abilities, $this->accessTokenExpiresAt, $expiresAt, $this->familyExpiresAt);
    }

    /**
     * The absolute cap on the family's total life. Passing null here does not
     * uncap the family: it falls back to configuration. Configure the cap as
     * null to issue uncapped families.
     */
    public function withFamilyExpiresAt(?DateTimeInterface $expiresAt): self
    {
        return new self($this->name, $this->abilities, $this->accessTokenExpiresAt, $this->refreshTokenExpiresAt, $expiresAt);
    }
}
