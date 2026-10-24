<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Events;

use Puneetpal\AppVersion\ClientVersion;
use Puneetpal\AppVersion\UpdateRequirement;
use Puneetpal\AppVersion\VersionPolicy;

/**
 * Dispatched once per request that carried X-App-* headers, when
 * config('app-version.usage.enabled') is true. Listen to it to feed your own
 * metrics; the package's CacheUsageRecorder listens to it for app-version:report.
 */
final class AppVersionSeen
{
    public function __construct(
        public readonly ClientVersion $client,
        public readonly UpdateRequirement $requirement,
        public readonly ?VersionPolicy $policy,
        public readonly string $path,
    ) {
    }
}
