<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

/**
 * The header names the client sends on every request and the response headers
 * the server adds for versions below the recommended one.
 */
final class Headers
{
    public const VERSION = 'X-App-Version';

    public const BUILD = 'X-App-Build';

    public const PLATFORM = 'X-App-Platform';

    public const DEPRECATION = 'Deprecation';

    public const SUNSET = 'Sunset';

    public const LINK = 'Link';

    private function __construct()
    {
    }
}
