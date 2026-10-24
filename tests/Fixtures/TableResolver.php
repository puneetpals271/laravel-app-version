<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Fixtures;

use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\Version;
use Puneetpal\AppVersion\VersionPolicy;

/**
 * Stands in for a resolver that reads a releases table.
 */
final class TableResolver implements PolicyResolver
{
    public function resolve(string $platform): ?VersionPolicy
    {
        if ($platform !== 'ios') {
            return null;
        }

        return new VersionPolicy(
            'ios',
            Version::parse('7.0.0'),
            Version::parse('7.2.0'),
            Version::parse('7.2.1'),
            storeUrl: 'https://apps.apple.com/app/id111111111',
            message: 'From the table',
        );
    }
}
