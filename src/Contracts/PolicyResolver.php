<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Contracts;

use Puneetpal\AppVersion\VersionPolicy;

/**
 * Looks up the policy for a platform. Bind your own implementation in the
 * container, name it in config('app-version.resolver'), or pass a closure to
 * AppVersion::resolveUsing(). Return null for a platform you do not serve.
 */
interface PolicyResolver
{
    public function resolve(string $platform): ?VersionPolicy;
}
