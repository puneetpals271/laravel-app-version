<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;
use Puneetpal\AppVersion\Exceptions\InvalidVersion;

/**
 * The version policy for one platform. Invariant: minimum <= recommended <= latest.
 *
 * toArray() is the exact JSON body of GET /app/version-policy and of a 426
 * response, so its keys are part of the shared protocol and must not change.
 */
final class VersionPolicy implements Arrayable, JsonSerializable
{
    public readonly ?Carbon $deprecatedAt;

    public readonly ?Carbon $sunset;

    /**
     * Date strings without a time zone are read as UTC, so a policy renders
     * the same headers whatever the application's timezone is.
     *
     * @param  string|DateTimeInterface|null  $deprecatedAt  when versions below recommended became (or become) deprecated
     * @param  string|DateTimeInterface|null  $sunset  when versions below recommended are expected to stop working
     *
     * @throws InvalidPolicy
     */
    public function __construct(
        public readonly string $platform,
        public readonly Version $minimum,
        public readonly Version $recommended,
        public readonly Version $latest,
        public readonly ?string $storeUrl = null,
        string|DateTimeInterface|null $deprecatedAt = null,
        string|DateTimeInterface|null $sunset = null,
        public readonly ?string $message = null,
    ) {
        if ($minimum->compare($recommended) > 0 || $recommended->compare($latest) > 0) {
            throw InvalidPolicy::order($platform);
        }

        if ($storeUrl !== null && filter_var($storeUrl, FILTER_VALIDATE_URL) === false) {
            throw InvalidPolicy::storeUrl($platform, $storeUrl);
        }

        $this->deprecatedAt = self::parseDate($platform, 'deprecated_at', $deprecatedAt);
        $this->sunset = self::parseDate($platform, 'sunset', $sunset);

        if ($this->sunset !== null && $this->deprecatedAt !== null && $this->sunset->lessThan($this->deprecatedAt)) {
            throw InvalidPolicy::sunsetBeforeDeprecation($platform);
        }
    }

    /**
     * Builds a policy from the config shape. "minimum" is required; "recommended"
     * defaults to minimum (no soft prompt) and "latest" defaults to recommended.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidPolicy
     */
    public static function fromArray(string $platform, array $data): self
    {
        $minimum = self::versionFrom($platform, $data, 'minimum');

        if ($minimum === null) {
            throw InvalidPolicy::missing($platform, 'minimum');
        }

        $recommended = self::versionFrom($platform, $data, 'recommended') ?? $minimum;
        $latest = self::versionFrom($platform, $data, 'latest') ?? $recommended;

        $storeUrl = $data['store_url'] ?? null;
        $message = $data['message'] ?? null;

        return new self(
            $platform,
            $minimum,
            $recommended,
            $latest,
            is_string($storeUrl) && $storeUrl !== '' ? $storeUrl : null,
            self::dateFrom($data, 'deprecated_at'),
            self::dateFrom($data, 'sunset'),
            is_string($message) && $message !== '' ? $message : null,
        );
    }

    public function evaluate(Version $version): UpdateRequirement
    {
        if ($version->isLowerThan($this->minimum)) {
            return UpdateRequirement::Hard;
        }

        if ($version->isLowerThan($this->recommended)) {
            return UpdateRequirement::Soft;
        }

        return UpdateRequirement::None;
    }

    /**
     * Response headers for a client below the recommended version. Deprecation is
     * the RFC 9745 form, a Structured Fields date such as "@1793491200", and is
     * sent only when the policy has a deprecated_at date.
     *
     * @return array<string, string>
     */
    public function deprecationHeaders(): array
    {
        $headers = [];

        if ($this->deprecatedAt !== null) {
            $headers[Headers::DEPRECATION] = '@'.$this->deprecatedAt->getTimestamp();
        }

        if ($this->sunset !== null) {
            $headers[Headers::SUNSET] = $this->sunset->toRfc7231String();
        }

        if ($this->storeUrl !== null) {
            $headers[Headers::LINK] = sprintf('<%s>; rel="alternate"', $this->storeUrl);
        }

        return $headers;
    }

    /**
     * @return array{platform: string, minimum: string, recommended: string, latest: string, store_url: string|null, message: string|null}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'minimum' => $this->minimum->toString(),
            'recommended' => $this->recommended->toString(),
            'latest' => $this->latest->toString(),
            'store_url' => $this->storeUrl,
            'message' => $this->message,
        ];
    }

    /**
     * @return array{platform: string, minimum: string, recommended: string, latest: string, store_url: string|null, message: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function versionFrom(string $platform, array $data, string $key): ?Version
    {
        $value = $data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Version) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw InvalidPolicy::version($platform, $key, get_debug_type($value));
        }

        try {
            return Version::parse((string) $value);
        } catch (InvalidVersion) {
            throw InvalidPolicy::version($platform, $key, (string) $value);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function dateFrom(array $data, string $key): string|DateTimeInterface|null
    {
        $value = $data[$key] ?? null;

        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function parseDate(string $platform, string $key, string|DateTimeInterface|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            // The timezone argument only applies when the string carries none of its own.
            return Carbon::parse($value, 'UTC');
        } catch (InvalidFormatException) {
            throw InvalidPolicy::date($platform, $key, $value);
        }
    }
}
