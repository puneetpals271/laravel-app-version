<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a resolved policy cannot be served: a missing or malformed
 * version, versions out of order, a sunset date that does not parse, or a
 * store URL that is not a URL. It fails loudly on purpose; a wrong policy
 * can lock every user out of the app.
 */
final class InvalidPolicy extends InvalidArgumentException
{
    public static function missing(string $platform, string $key): self
    {
        return new self(sprintf('The app version policy for "%s" has no "%s" version.', $platform, $key));
    }

    public static function version(string $platform, string $key, string $value): self
    {
        return new self(sprintf('The app version policy for "%s" has an invalid "%s" version "%s".', $platform, $key, $value));
    }

    public static function order(string $platform): self
    {
        return new self(sprintf(
            'The app version policy for "%s" is out of order: minimum must not exceed recommended, and recommended must not exceed latest.',
            $platform,
        ));
    }

    public static function date(string $platform, string $key, string $value): self
    {
        return new self(sprintf('The app version policy for "%s" has a %s date "%s" that cannot be parsed.', $platform, $key, $value));
    }

    public static function sunsetBeforeDeprecation(string $platform): self
    {
        return new self(sprintf(
            'The app version policy for "%s" has a sunset date earlier than its deprecated_at date; a version cannot stop working before it is deprecated.',
            $platform,
        ));
    }

    public static function storeUrl(string $platform, string $value): self
    {
        return new self(sprintf('The app version policy for "%s" has a store_url "%s" that is not an absolute URL.', $platform, $value));
    }

    public static function resolver(string $returned): self
    {
        return new self(sprintf(
            'An app version policy resolver must return a %s, an array or null; got %s.',
            \Puneetpal\AppVersion\VersionPolicy::class,
            $returned,
        ));
    }
}
