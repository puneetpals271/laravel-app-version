<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Resolvers;

use Illuminate\Contracts\Config\Repository;
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\VersionPolicy;

/**
 * The default resolver: reads config('app-version.platforms.<platform>').
 */
final class ConfigPolicyResolver implements PolicyResolver
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function resolve(string $platform): ?VersionPolicy
    {
        $data = $this->config->get('app-version.platforms.'.$platform);

        if (! is_array($data) || $data === []) {
            return null;
        }

        return VersionPolicy::fromArray($platform, $data);
    }
}
