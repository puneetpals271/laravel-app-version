<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

use Illuminate\Http\Request;
use Puneetpal\AppVersion\Exceptions\InvalidClientHeaders;
use Puneetpal\AppVersion\Exceptions\InvalidVersion;

/**
 * The X-App-* headers of one request, parsed. The platform is a normalised
 * lower-case name (see Platform); the build is optional.
 */
final class ClientVersion
{
    /** Longest X-App-Build accepted, in digits, so the value always fits a 64-bit integer. */
    private const BUILD_DIGITS_LIMIT = 18;

    public function __construct(
        public readonly Version $version,
        public readonly ?string $platform = null,
        public readonly ?int $build = null,
    ) {
    }

    /**
     * Returns null when the request carries no X-App-Version header.
     *
     * @throws InvalidClientHeaders when a header is present but malformed
     */
    public static function fromRequest(Request $request): ?self
    {
        $rawVersion = self::headerValue($request, Headers::VERSION);

        if ($rawVersion === null) {
            return null;
        }

        try {
            $version = Version::parse($rawVersion);
        } catch (InvalidVersion) {
            throw InvalidClientHeaders::version($rawVersion);
        }

        $build = null;
        $rawBuild = self::headerValue($request, Headers::BUILD);

        if ($rawBuild !== null) {
            if (! ctype_digit($rawBuild) || strlen($rawBuild) > self::BUILD_DIGITS_LIMIT) {
                throw InvalidClientHeaders::build($rawBuild);
            }

            $build = (int) $rawBuild;
        }

        $platform = null;
        $rawPlatform = self::headerValue($request, Headers::PLATFORM);

        if ($rawPlatform !== null) {
            $platform = Platform::tryNormalise($rawPlatform);

            if ($platform === null) {
                throw InvalidClientHeaders::platform($rawPlatform);
            }
        }

        return new self($version, $platform, $build);
    }

    /**
     * Like fromRequest(), but returns null instead of throwing on malformed headers.
     */
    public static function tryFromRequest(Request $request): ?self
    {
        try {
            return self::fromRequest($request);
        } catch (InvalidClientHeaders) {
            return null;
        }
    }

    public function hasPlatform(): bool
    {
        return $this->platform !== null;
    }

    /**
     * @return array{version: string, build: int|null, platform: string|null}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version->toString(),
            'build' => $this->build,
            'platform' => $this->platform,
        ];
    }

    /**
     * The trimmed header value, or null when the header is absent or blank.
     */
    private static function headerValue(Request $request, string $name): ?string
    {
        $value = $request->headers->get($name);

        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
