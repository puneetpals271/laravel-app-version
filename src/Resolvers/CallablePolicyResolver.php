<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Resolvers;

use Closure;
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;
use Puneetpal\AppVersion\VersionPolicy;

/**
 * Wraps a closure such as fn (string $platform) => AppRelease::policyFor($platform).
 * The closure may return a VersionPolicy, the config array shape, or null.
 */
final class CallablePolicyResolver implements PolicyResolver
{
    public function __construct(private readonly Closure $callback)
    {
    }

    public function resolve(string $platform): ?VersionPolicy
    {
        $result = ($this->callback)($platform);

        if ($result === null || $result instanceof VersionPolicy) {
            return $result;
        }

        if (is_array($result)) {
            return $result === [] ? null : VersionPolicy::fromArray($platform, $result);
        }

        throw InvalidPolicy::resolver(get_debug_type($result));
    }
}
