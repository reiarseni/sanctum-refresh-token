<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\Support;

use Illuminate\Contracts\Config\Repository as Config;
use Reiarseni\SanctumRefreshToken\Exceptions\ConfigurationException;

/**
 * Keyed hashing of observed client metadata.
 *
 * A bare SHA-256 of an IP address is not a protection: the IPv4 space is 2^32
 * entries and a rainbow table over it is trivial to build. Keying the hash on
 * the application key makes the stored value useless to anyone who does not
 * also hold that key, while still changing whenever the input changes — which
 * is the only property the session read model actually needs.
 */
final class MetadataHasher
{
    public function __construct(
        private readonly Config $config,
        private readonly Settings $settings,
    ) {}

    /**
     * Whether observed metadata is stored as it was seen rather than hashed.
     */
    public function storesPlaintext(): bool
    {
        return $this->settings->bool('sanctum-refresh-token.security.store_metadata_plaintext');
    }

    /**
     * The value to persist for one piece of observed metadata.
     */
    public function prepare(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->storesPlaintext() ? $value : $this->hash($value);
    }

    /**
     * @throws ConfigurationException when no application key is available
     */
    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key());
    }

    private function key(): string
    {
        $key = $this->config->get('app.key');

        if (! is_string($key) || $key === '') {
            throw ConfigurationException::missingApplicationKey();
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw ConfigurationException::missingApplicationKey();
            }

            return $decoded;
        }

        return $key;
    }
}
