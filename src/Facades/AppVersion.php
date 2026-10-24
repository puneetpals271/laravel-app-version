<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Puneetpal\AppVersion\AppVersion resolveUsing(\Puneetpal\AppVersion\Contracts\PolicyResolver|\Closure $resolver)
 * @method static \Puneetpal\AppVersion\Contracts\PolicyResolver resolver()
 * @method static \Puneetpal\AppVersion\VersionPolicy|null policy(string $platform)
 * @method static \Puneetpal\AppVersion\ClientVersion|null client(\Illuminate\Http\Request $request)
 * @method static \Puneetpal\AppVersion\UpdateRequirement evaluate(\Puneetpal\AppVersion\ClientVersion $client)
 *
 * @see \Puneetpal\AppVersion\AppVersion
 */
final class AppVersion extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Puneetpal\AppVersion\AppVersion::class;
    }
}
