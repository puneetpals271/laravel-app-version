<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform policies
    |--------------------------------------------------------------------------
    |
    | One entry per platform the client sends in X-App-Platform. Versions are
    | semantic versions (3.4.1). "minimum" is required. "recommended" defaults
    | to minimum (so nothing is soft-deprecated) and "latest" defaults to
    | recommended. The three must be in order: minimum <= recommended <= latest.
    |
    | Below minimum the API answers 426 Upgrade Required. Below recommended the
    | response gains Deprecation: @<unix-timestamp> (RFC 9745) when
    | "deprecated_at" is set, Sunset: <HTTP-date> (RFC 8594) when "sunset" is
    | set, and Link: <store_url>; rel="alternate" when "store_url" is set.
    |
    | "deprecated_at" is the date you raised "recommended"; "sunset" is the date
    | you plan to raise "minimum" and must not be earlier than "deprecated_at".
    | Both accept any date Carbon can parse, such as 2026-11-01 (ISO 8601), and
    | are read as UTC unless they carry a time zone. "message" is returned to
    | the client verbatim.
    |
    */

    'platforms' => [

        'ios' => [
            'minimum' => env('APP_VERSION_IOS_MINIMUM', '1.0.0'),
            'recommended' => env('APP_VERSION_IOS_RECOMMENDED'),
            'latest' => env('APP_VERSION_IOS_LATEST'),
            'store_url' => env('APP_VERSION_IOS_STORE_URL'),
            'deprecated_at' => env('APP_VERSION_IOS_DEPRECATED_AT'),
            'sunset' => env('APP_VERSION_IOS_SUNSET'),
            'message' => env('APP_VERSION_IOS_MESSAGE'),
        ],

        'android' => [
            'minimum' => env('APP_VERSION_ANDROID_MINIMUM', '1.0.0'),
            'recommended' => env('APP_VERSION_ANDROID_RECOMMENDED'),
            'latest' => env('APP_VERSION_ANDROID_LATEST'),
            'store_url' => env('APP_VERSION_ANDROID_STORE_URL'),
            'deprecated_at' => env('APP_VERSION_ANDROID_DEPRECATED_AT'),
            'sunset' => env('APP_VERSION_ANDROID_SUNSET'),
            'message' => env('APP_VERSION_ANDROID_MESSAGE'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Policy resolver
    |--------------------------------------------------------------------------
    |
    | Where policies come from. Null reads the "platforms" array above. Set a
    | class name implementing Puneetpal\AppVersion\Contracts\PolicyResolver to
    | read them from a table or a remote config service instead, or call
    | AppVersion::resolveUsing(fn (string $platform) => ...) in a provider.
    |
    */

    'resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Strict mode
    |--------------------------------------------------------------------------
    |
    | When false, requests without X-App-Version pass through untouched, so
    | curl, partners and builds that predate the headers keep working. When
    | true, a request without X-App-Version and X-App-Platform, or with a
    | platform that has no policy, gets 400 Bad Request. Malformed header
    | values get 400 in both modes.
    |
    */

    'strict' => (bool) env('APP_VERSION_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Log context
    |--------------------------------------------------------------------------
    |
    | Add app_version, app_build and app_platform to Laravel's log Context so
    | every log line written during the request carries them.
    |
    */

    'context' => true,

    /*
    |--------------------------------------------------------------------------
    | Version policy endpoint
    |--------------------------------------------------------------------------
    |
    | GET {prefix}/version-policy?platform=ios returns the policy JSON. Keep it
    | outside any API version prefix so its URL never moves. The middleware
    | list is applied as-is; the "api" group exists in every Laravel 12
    | application. The enforcing middleware is always excluded from this route,
    | even if the "api" group carries it, so a hard-blocked client can still
    | read the policy. "cache_max_age" sets Cache-Control public, max-age; 0
    | sends no-store.
    |
    */

    'route' => [
        'enabled' => (bool) env('APP_VERSION_ROUTE', true),
        'prefix' => 'app',
        'middleware' => ['api'],
        'cache_max_age' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage recording
    |--------------------------------------------------------------------------
    |
    | When enabled, the middleware dispatches Puneetpal\AppVersion\Events\AppVersionSeen
    | for every request that carried X-App-Version. With "record" on, the package
    | also counts requests per day, platform and version in the cache store named
    | here (null is the default store), kept for "retention_days", so that
    | `php artisan app-version:report` can show who still runs an old build.
    | Turn "record" off to keep the event but feed your own metrics instead.
    |
    */

    'usage' => [
        'enabled' => (bool) env('APP_VERSION_USAGE', false),
        'record' => true,
        'store' => env('APP_VERSION_USAGE_STORE'),
        'prefix' => 'app-version:usage',
        'retention_days' => 90,
    ],

];
