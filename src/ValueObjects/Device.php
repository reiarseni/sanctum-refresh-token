<?php

declare(strict_types=1);

namespace Reiarseni\SanctumRefreshToken\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What is known about the client a family was last used from.
 *
 * How much that is depends on a deliberate configuration choice. By default the
 * observed metadata is stored as a keyed hash, so the package can tell you the
 * device changed but cannot tell you what it was: every readable field here is
 * null and `isAvailable()` reports false. Turning on plaintext storage makes
 * the address and the user agent readable, and lets the platform, application
 * and operating system be derived from the latter.
 *
 * That default is the point. An application that wants a human-readable "your
 * devices" screen takes the data-protection decision knowingly, instead of
 * inheriting a table full of IP addresses it never asked for.
 *
 * @implements Arrayable<string, mixed>
 */
final class Device implements Arrayable
{
    private function __construct(
        public readonly ?string $platform,
        public readonly ?string $application,
        public readonly ?string $operatingSystem,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
    ) {}

    /**
     * Nothing readable: either the metadata is hashed, or there was no request
     * to observe when the family was opened.
     */
    public static function unavailable(): self
    {
        return new self(null, null, null, null, null);
    }

    /**
     * Build from metadata stored in plaintext.
     */
    public static function fromMetadata(?string $ipAddress, ?string $userAgent): self
    {
        if (($ipAddress === null || $ipAddress === '') && ($userAgent === null || $userAgent === '')) {
            return self::unavailable();
        }

        return new self(
            platform: self::platform($userAgent),
            application: self::application($userAgent),
            operatingSystem: self::operatingSystem($userAgent),
            ipAddress: $ipAddress !== '' ? $ipAddress : null,
            userAgent: $userAgent !== '' ? $userAgent : null,
        );
    }

    /**
     * Whether anything readable is known about the device.
     */
    public function isAvailable(): bool
    {
        return $this->ipAddress !== null || $this->userAgent !== null;
    }

    /**
     * @return array{
     *     platform: string|null,
     *     application: string|null,
     *     operating_system: string|null,
     *     ip_address: string|null,
     *     user_agent: string|null,
     *     available: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'application' => $this->application,
            'operating_system' => $this->operatingSystem,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'available' => $this->isAvailable(),
        ];
    }

    /**
     * Coarse classification only. This is not a user-agent parsing library and
     * does not pretend to be one: it answers "phone, tablet or desktop" well
     * enough for a session list and stops there.
     */
    private static function platform(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\b(iPad|Tablet)\b/i', $userAgent) => 'tablet',
            (bool) preg_match('/\b(Mobile|iPhone|Android|Dart|okhttp|CFNetwork)\b/i', $userAgent) => 'mobile',
            default => 'desktop',
        };
    }

    private static function application(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\bEdg\//', $userAgent) => 'Edge',
            (bool) preg_match('/\bOPR\//', $userAgent) => 'Opera',
            (bool) preg_match('/\bChrome\//', $userAgent) => 'Chrome',
            (bool) preg_match('/\bFirefox\//', $userAgent) => 'Firefox',
            (bool) preg_match('/\bSafari\//', $userAgent) => 'Safari',
            (bool) preg_match('/\b(Dart|okhttp|CFNetwork|Alamofire)\b/i', $userAgent) => 'Native client',
            default => null,
        };
    }

    private static function operatingSystem(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            (bool) preg_match('/\b(iPhone|iPad|iOS|CFNetwork)\b/i', $userAgent) => 'iOS',
            (bool) preg_match('/\bAndroid\b/i', $userAgent) => 'Android',
            (bool) preg_match('/\bMac OS X\b/i', $userAgent) => 'macOS',
            (bool) preg_match('/\bWindows\b/i', $userAgent) => 'Windows',
            (bool) preg_match('/\bLinux\b/i', $userAgent) => 'Linux',
            default => null,
        };
    }
}
