<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

/**
 * What a client on a given version must do, as the policy sees it.
 */
enum UpdateRequirement: string
{
    /** The version is at or above the recommended one. */
    case None = 'none';

    /** Below recommended but at or above minimum: a dismissible prompt. */
    case Soft = 'soft';

    /** Below minimum: the API refuses to serve and the app must block. */
    case Hard = 'hard';

    public function isBlocking(): bool
    {
        return $this === self::Hard;
    }

    public function isDeprecated(): bool
    {
        return $this !== self::None;
    }
}
