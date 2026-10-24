<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

/**
 * Platform names as the protocol allows them: a short lower-case token such
 * as "ios" or "android". The name is used as a config key and echoed back in
 * responses, so anything else is treated as malformed rather than looked up.
 */
final class Platform
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private function __construct()
    {
    }

    /**
     * Trims and lower-cases a platform name, or returns null when the result
     * is not a valid token. An empty value is also null.
     */
    public static function tryNormalise(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = strtolower(trim($value));

        return preg_match(self::PATTERN, $normalised) === 1 ? $normalised : null;
    }

    public static function isValid(string $value): bool
    {
        return self::tryNormalise($value) !== null;
    }
}
