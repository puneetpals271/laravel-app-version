# laravel-app-version

A minimum, recommended and latest app version policy for Laravel APIs that serve iOS
and Android apps. One middleware reads the version the app sends, answers
`426 Upgrade Required` to builds you no longer serve, adds `Deprecation` and `Sunset`
headers to builds you are about to stop serving, and leaves everything else alone. A
small endpoint publishes the policy so the app can show the update screen before it
even calls your API.

- PHP 8.2 or later, Laravel 12 or 13
- No dependencies beyond the framework
- Pairs with [AppUpdateGate](https://github.com/puneetpals271/AppUpdateGate), the Swift
  client half of the same protocol

## The problem

You cannot force a mobile user to install an update. You can only refuse to serve a build
that is too old, and tell the ones in between that their time is running out. Doing that
by hand means parsing a version header in every project, remembering that `1.10.0` is
newer than `1.9.0`, agreeing with the app team on what a blocked response looks like,
and keeping the "which versions still call us" question answerable. This package fixes
one answer to each of those and tests it.

## Requirements

- PHP 8.2, 8.3 or 8.4 (the suite also passes on PHP 8.5)
- Laravel 12 or 13 (`illuminate/console`, `illuminate/contracts`, `illuminate/http`,
  `illuminate/routing` and `illuminate/support` 12.x or 13.x). Laravel 13 itself requires
  PHP 8.3.
- The `mbstring`, `json` and `ctype` extensions, which Laravel already requires
- A cache store, only if you turn on usage recording

## Installation

1. Install the package. The service provider and the `AppVersion` facade are registered
   through package discovery.

   ```sh
   composer require puneetpal/laravel-app-version
   ```

2. Publish the configuration file to `config/app-version.php`.

   ```sh
   php artisan vendor:publish --tag=app-version-config
   ```

3. Set the versions for each platform in `.env`. Only `*_MINIMUM` is required; the others
   default sensibly (`RECOMMENDED` to `MINIMUM`, `LATEST` to `RECOMMENDED`). Use the
   marketing version your apps report (`CFBundleShortVersionString` on iOS,
   `versionName` on Android), not the build number.

   ```dotenv
   APP_VERSION_IOS_MINIMUM=3.0.0
   APP_VERSION_IOS_RECOMMENDED=3.4.0
   APP_VERSION_IOS_LATEST=3.4.1
   APP_VERSION_IOS_STORE_URL=https://apps.apple.com/app/id000000000
   APP_VERSION_IOS_DEPRECATED_AT=2026-11-01
   APP_VERSION_IOS_SUNSET=2027-03-01
   APP_VERSION_IOS_MESSAGE=

   APP_VERSION_ANDROID_MINIMUM=2.0.0
   APP_VERSION_ANDROID_RECOMMENDED=2.5.0
   APP_VERSION_ANDROID_LATEST=2.5.3
   APP_VERSION_ANDROID_STORE_URL=https://play.google.com/store/apps/details?id=com.example.app
   APP_VERSION_ANDROID_DEPRECATED_AT=
   APP_VERSION_ANDROID_SUNSET=
   APP_VERSION_ANDROID_MESSAGE=
   ```

   `*_DEPRECATED_AT` is the date you raised `*_RECOMMENDED` and becomes the `Deprecation`
   header; `*_SUNSET` is the date you plan to raise `*_MINIMUM` and becomes the `Sunset`
   header. Both are ISO 8601 dates, read as UTC unless they carry a time zone, and both
   are optional; leave them empty to send neither header. If your configuration is
   cached, run `php artisan config:cache` again afterwards.

## Setup

1. Register the middleware in `bootstrap/app.php`. The package already registers the
   `app.version` alias from its service provider; declaring it here as well keeps the
   name visible to whoever reads the file next, and is required if you have turned
   package discovery off. A new Laravel application has no `routes/api.php` and no
   `api:` entry in `withRouting()` until you run `php artisan install:api`; do that
   first if your API routes do not exist yet.

   ```php
   use Illuminate\Foundation\Configuration\Middleware;
   use Puneetpal\AppVersion\Http\Middleware\EnforceAppVersion;

   return Application::configure(basePath: dirname(__DIR__))
       ->withRouting(
           api: __DIR__.'/../routes/api.php',
           // ...
       )
       ->withMiddleware(function (Middleware $middleware): void {
           $middleware->alias([
               'app.version' => EnforceAppVersion::class,
           ]);
       })
       ->create();
   ```

2. Apply it. Either enforce on every route in the `api` group:

   ```php
   ->withMiddleware(function (Middleware $middleware): void {
       $middleware->alias([
           'app.version' => EnforceAppVersion::class,
       ]);

       $middleware->api(append: [
           EnforceAppVersion::class,
       ]);
   })
   ```

   or only on the routes your mobile apps call, in `routes/api.php`:

   ```php
   use Illuminate\Support\Facades\Route;

   Route::middleware('app.version')->prefix('v2')->group(function (): void {
       Route::get('orders', [OrderController::class, 'index']);
       // ...
   });
   ```

   Do not add it to the `web` group or to the global stack. Browsers never send the
   headers, and in strict mode they would get a `400`.

3. Enable the policy endpoint. It is on by default and answers
   `GET /app/version-policy?platform=ios`. Confirm it is registered:

   ```sh
   php artisan route:list --path=version-policy
   ```

   To turn it off, set `APP_VERSION_ROUTE=false`. To move it, change `route.prefix` in
   `config/app-version.php`. Keep it outside any API version prefix (`/api/v2`) so its URL
   never changes; the app calls it from every version you have ever shipped. The
   enforcing middleware is always excluded from this route, even when the `api` group
   carries it, so a blocked client can still read the policy.

## Verify it works

Start the application (`php artisan serve`) and call any route behind the middleware.
`/api/orders` below stands for one of yours. With the `.env` values from the
installation step, you should see the following. Headers that Laravel or the web server
add on their own (`Date`, `Host`, `Cache-Control: no-cache, private`, rate limiting,
CORS) are omitted here.

**Below minimum.** The request is not served; the body is the policy.

```sh
curl -i http://127.0.0.1:8000/api/orders \
  -H "Accept: application/json" \
  -H "X-App-Platform: ios" \
  -H "X-App-Version: 2.9.9" \
  -H "X-App-Build: 87"
```

```http
HTTP/1.1 426 Upgrade Required
Content-Type: application/json
Deprecation: @1793491200
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://apps.apple.com/app/id000000000>; rel="alternate"
Vary: X-App-Version, X-App-Platform

{"platform":"ios","minimum":"3.0.0","recommended":"3.4.0","latest":"3.4.1","store_url":"https://apps.apple.com/app/id000000000","message":null}
```

**Below recommended.** The request is served as usual, with advisory headers.

```sh
curl -i http://127.0.0.1:8000/api/orders \
  -H "Accept: application/json" \
  -H "X-App-Platform: ios" \
  -H "X-App-Version: 3.2.0"
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
Deprecation: @1793491200
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://apps.apple.com/app/id000000000>; rel="alternate"
Vary: X-App-Version, X-App-Platform

{"data":[...]}
```

**Current.** Nothing is added except `Vary`, which tells shared caches that this
response depends on the client's version.

```sh
curl -i http://127.0.0.1:8000/api/orders \
  -H "Accept: application/json" \
  -H "X-App-Platform: ios" \
  -H "X-App-Version: 3.4.1"
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
Vary: X-App-Version, X-App-Platform

{"data":[...]}
```

**The policy endpoint.** Cacheable for five minutes by default.

```sh
curl -i "http://127.0.0.1:8000/app/version-policy?platform=ios"
```

```http
HTTP/1.1 200 OK
Cache-Control: max-age=300, public
Content-Type: application/json

{"platform":"ios","minimum":"3.0.0","recommended":"3.4.0","latest":"3.4.1","store_url":"https://apps.apple.com/app/id000000000","message":null}
```

**From the console.** Prints the policy exactly as the API is serving it, one block per
configured platform (the `android` block is omitted below), which is the quickest way to
confirm that `.env` and the config cache agree.

```sh
php artisan app-version:policy
```

```
  ios ........................................................................
  Minimum .............................................................. 3.0.0
  Recommended .......................................................... 3.4.0
  Latest ............................................................... 3.4.1
  Store URL ........................... https://apps.apple.com/app/id000000000
  Deprecated at .................. Sun, 01 Nov 2026 00:00:00 GMT (@1793491200)
  Sunset ....................................... Mon, 01 Mar 2027 00:00:00 GMT
  Message ............................................................... none
```

A request without any `X-App-*` header passes through untouched unless strict mode is
on. A malformed header (`X-App-Version: latest`, `X-App-Build: 1.2`,
`X-App-Platform: i/os`) is a `400` with a JSON body of
`{"error": "invalid_app_version", "message": "..."}` (or `invalid_app_build`,
`invalid_app_platform`) in both modes. The message quotes at most 64 printable
characters of the offending value, so a hostile header never reaches your logs or the
client unfiltered.

## The protocol

The same protocol is implemented by AppUpdateGate on iOS, so the two packages can be
adopted independently and still agree.

**Request headers.** The app sends these on every request.

| Header           | Value                                                                 | Required |
| ---------------- | --------------------------------------------------------------------- | -------- |
| `X-App-Version`  | Semantic version, for example `3.4.1`                                 | Yes      |
| `X-App-Platform` | `ios` or `android` (case-insensitive; see below for other platforms)  | Yes      |
| `X-App-Build`    | Integer build number, for example `120`                               | No       |

A platform name is a token of 1 to 32 lower-case letters, digits, `-` and `_`, starting
with a letter or digit; the header is lower-cased before it is compared with the
configured platforms. Anything else is malformed and answered with `400`.

Versions may have one to three numeric components (`3`, `3.4` and `3.4.0` are equal), an
optional pre-release suffix (`3.4.1-beta.2`, which sorts below `3.4.1`) and optional
build metadata (`3.4.1+77`, ignored when comparing), following
[Semantic Versioning 2.0.0](https://semver.org/). A leading `v` is accepted.

**Policy endpoint.** `GET /app/version-policy?platform=ios` returns:

```json
{
  "platform": "ios",
  "minimum": "3.0.0",
  "recommended": "3.4.0",
  "latest": "3.4.1",
  "store_url": "https://apps.apple.com/app/id000000000",
  "message": null
}
```

`store_url` and `message` are `null` when not configured. The key set and order are
part of the protocol and do not change within a major version. When `?platform=` is
absent the endpoint falls back to the `X-App-Platform` header; with neither it answers
`400`, and with a platform that has no policy it answers `404`.

**Policy semantics.** Given the client's version `v`:

| Condition                         | Requirement | Server behaviour                                     | App behaviour                 |
| --------------------------------- | ----------- | ---------------------------------------------------- | ----------------------------- |
| `v < minimum`                     | hard        | `426 Upgrade Required`, body is the policy JSON      | Blocking screen, store link   |
| `minimum <= v < recommended`      | soft        | Normal response plus `Deprecation`, `Sunset`, `Link` | Dismissible prompt            |
| `v >= recommended`                | none        | Normal response                                      | Nothing                       |

**Below minimum.** Every request from such a client, on any route behind the
middleware, gets `426` with the policy as the body, so the app can show the hard-update
screen from any call rather than only at launch. The `426` carries the same advisory
headers as a soft update.

**Below recommended.** The response gains:

```http
Deprecation: @1793491200
Sunset: Mon, 01 Mar 2027 00:00:00 GMT
Link: <https://apps.apple.com/app/id000000000>; rel="alternate"
```

Each header is sent only when the platform has the value behind it, so a policy with
no dates and no store URL adds nothing to a soft-update response:

- `Deprecation` ([RFC 9745](https://www.rfc-editor.org/rfc/rfc9745)) is a Structured
  Fields date: `@` followed by the Unix timestamp of the platform's `deprecated_at`. A
  date in the past means the version is deprecated; a date in the future announces
  that it will be.
- `Sunset` ([RFC 8594](https://www.rfc-editor.org/rfc/rfc8594)) is the platform's
  `sunset` as an HTTP-date in GMT, never earlier than `deprecated_at` (the package
  rejects such a policy).
- `Link` is `<store_url>; rel="alternate"`, added alongside any `Link` header your route
  already set (pagination, for instance), not in place of it.

## Pairing with AppUpdateGate

[AppUpdateGate](https://github.com/puneetpals271/AppUpdateGate) is the Swift side: it
sets the three `X-App-*` headers on a `URLRequest`, recognises a `426` and decodes its
body into the policy, reads the `Deprecation`, `Sunset` and `Link` headers of ordinary
responses, and fetches `/app/version-policy` at launch. An Android client needs no
library; the protocol is three request headers, one JSON document and a status code.

## Configuration reference

All keys live in `config/app-version.php`.

| Key                                  | Env                           | Default                 | Meaning                                                                                      |
| ------------------------------------ | ----------------------------- | ----------------------- | -------------------------------------------------------------------------------------------- |
| `platforms.<p>.minimum`              | `APP_VERSION_<P>_MINIMUM`     | `1.0.0`                 | Lowest version served. Required.                                                             |
| `platforms.<p>.recommended`          | `APP_VERSION_<P>_RECOMMENDED` | same as `minimum`       | Versions below it get the advisory headers.                                                  |
| `platforms.<p>.latest`               | `APP_VERSION_<P>_LATEST`      | same as `recommended`   | Shown to the app so it can say what is available.                                            |
| `platforms.<p>.store_url`            | `APP_VERSION_<P>_STORE_URL`   | `null`                  | Absolute URL; sent in the body and as `Link ... rel="alternate"`.                            |
| `platforms.<p>.deprecated_at`        | `APP_VERSION_<P>_DEPRECATED_AT` | `null`                | ISO 8601 date on which versions below `recommended` became deprecated; sent as `Deprecation: @<unix-timestamp>`. Read as UTC unless it carries a time zone. |
| `platforms.<p>.sunset`               | `APP_VERSION_<P>_SUNSET`      | `null`                  | ISO 8601 date after which they are expected to stop working; sent as the `Sunset` HTTP-date. Must not be earlier than `deprecated_at`. Read as UTC unless it carries a time zone. |
| `platforms.<p>.message`              | `APP_VERSION_<P>_MESSAGE`     | `null`                  | Returned verbatim to the app.                                                                |
| `resolver`                           |                               | `null`                  | Class implementing `PolicyResolver` to read policies from somewhere other than this file.    |
| `strict`                             | `APP_VERSION_STRICT`          | `false`                 | Reject requests without `X-App-Version` and `X-App-Platform`, or with an unknown platform, with `400`. |
| `context`                            |                               | `true`                  | Add `app_version`, `app_build`, `app_platform` to Laravel's log `Context` for the request.   |
| `route.enabled`                      | `APP_VERSION_ROUTE`           | `true`                  | Register the policy endpoint.                                                                |
| `route.prefix`                       |                               | `app`                   | URL prefix of the endpoint.                                                                  |
| `route.middleware`                   |                               | `['api']`               | Middleware applied to the endpoint. The enforcing middleware is always excluded.             |
| `route.cache_max_age`                |                               | `300`                   | Seconds for `Cache-Control: public, max-age`; `0` sends `no-store`.                          |
| `usage.enabled`                      | `APP_VERSION_USAGE`           | `false`                 | Dispatch `AppVersionSeen` for every request that carried the headers.                        |
| `usage.record`                       |                               | `true`                  | Count requests per day, platform and version in the cache for `app-version:report`.          |
| `usage.store`                        | `APP_VERSION_USAGE_STORE`     | `null` (default store)  | Cache store used for the counters.                                                           |
| `usage.prefix`                       |                               | `app-version:usage`     | Key prefix for the counters.                                                                 |
| `usage.retention_days`               |                               | `90`                    | How long counters are kept.                                                                  |

`<p>` is the platform key (`ios`, `android`); `<P>` is the same in upper case. Add further
platforms by adding entries to `platforms`. The three versions must satisfy
`minimum <= recommended <= latest`; a policy that does not is rejected with
`Puneetpal\AppVersion\Exceptions\InvalidPolicy` at request time, on purpose, because a
wrong policy can lock every user out.

## Using the parsed version in your code

The middleware attaches the parsed headers to the request. Anywhere you have a request:

```php
use Illuminate\Http\Request;

public function index(Request $request)
{
    $client = $request->appVersion();   // ?Puneetpal\AppVersion\ClientVersion

    if ($client !== null && $client->version->isLowerThan(Version::parse('3.3.0'))) {
        // Serve the older response shape to builds that cannot read the new one.
    }
}
```

`appVersion()` returns `null` when no `X-App-Version` header was sent or it was
malformed. Outside the middleware it parses the headers on demand, so it works on any
route. The `ClientVersion` has `version` (`Version`), `platform` (`?string`, lower case)
and `build` (`?int`), plus `toArray()`.

The `AppVersion` facade exposes the service:

```php
use Puneetpal\AppVersion\Facades\AppVersion;

AppVersion::policy('ios');                     // ?VersionPolicy
AppVersion::client($request);                  // ?ClientVersion, throws InvalidClientHeaders when malformed
AppVersion::evaluate($client);                 // UpdateRequirement::Hard | Soft | None
AppVersion::resolveUsing($resolverOrClosure);  // see below
```

`UpdateRequirement` is a string-backed enum (`hard`, `soft`, `none`) with `isBlocking()`
and `isDeprecated()`. `Version` offers `parse()`, `tryParse()`, `isValid()`,
`compare()`, `isLowerThan()`, `isAtLeast()`, `equals()` and the public `major`, `minor`,
`patch`, `preRelease` and `buildMetadata` properties. Header names are constants on
`Puneetpal\AppVersion\Headers`; `Puneetpal\AppVersion\Platform::tryNormalise()` applies
the platform-name rule to a string of your own.

## Policies from a table or a remote source

Set `resolver` in the config to a class implementing
`Puneetpal\AppVersion\Contracts\PolicyResolver`:

```php
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\VersionPolicy;

final class ReleaseTableResolver implements PolicyResolver
{
    public function resolve(string $platform): ?VersionPolicy
    {
        $release = AppRelease::query()->where('platform', $platform)->first();

        return $release === null ? null : VersionPolicy::fromArray($platform, [
            'minimum' => $release->minimum,
            'recommended' => $release->recommended,
            'latest' => $release->latest,
            'store_url' => $release->store_url,
            'deprecated_at' => $release->deprecated_at,
            'sunset' => $release->sunset_at,
            'message' => $release->message,
        ]);
    }
}
```

Or pass a closure from a service provider's `boot()` method. It may return a
`VersionPolicy`, the array shape above, or `null` for a platform you do not serve:

```php
AppVersion::resolveUsing(fn (string $platform) => Cache::remember(
    "app-version:policy:$platform",
    now()->addMinutes(5),
    fn () => AppRelease::policyArrayFor($platform),
));
```

The resolver is called once per request that carries a platform, so cache whatever is
expensive.

## Knowing who still runs an old build

Set `APP_VERSION_USAGE=true`. The middleware then dispatches
`Puneetpal\AppVersion\Events\AppVersionSeen` (with the `ClientVersion`, the
`UpdateRequirement`, the `VersionPolicy` or `null`, and the request path) for every
request that carried the headers, including the ones answered with `426`. Listen to it
to feed your own metrics.

With `usage.record` left on, the package also counts requests per day, platform and
version in your cache store, using one atomic increment per request, and summarises them:

```sh
php artisan app-version:report --days=14
php artisan app-version:report --platform=android
```

```
  ios ............................................... 4 requests over 7 day(s)
  Policy ...................... minimum 3.0.0, recommended 3.4.0, latest 3.4.1
  Below minimum .................................................... 1 (25.0%)
  Below recommended ................................................ 2 (50.0%)
+---------+----------+-------+-------------------+
| Version | Requests | Share | Status            |
+---------+----------+-------+-------------------+
| 3.4.1   | 2        | 50.0% | current           |
| 3.2.0   | 1        | 25.0% | below recommended |
| 2.9.9   | 1        | 25.0% | below minimum     |
+---------+----------+-------+-------------------+
```

The counters are a convenience, not an audit log: they live in the cache store, expire
after `usage.retention_days`, and disappear if the cache is flushed. There is no
database table on purpose; if you need durable history, listen to the event and write
to your own table or metrics pipeline.

## Troubleshooting

**The middleware does not run.** Check `php artisan route:list -vv --path=api` (two
`v`s; one only shows group names) and look for
`Puneetpal\AppVersion\Http\Middleware\EnforceAppVersion` in the middleware list of the
route you are calling. If it is absent, the route is not in the group you appended
to, or the route cache is stale: run `php artisan route:clear` (or `optimize:clear`). If
`app.version` is reported as an unknown class, package discovery is off and the
provider is not registered; add `Puneetpal\AppVersion\AppVersionServiceProvider::class`
to `bootstrap/providers.php` or use the class name instead of the alias.

**Every request is a 426.** Run `php artisan app-version:policy` and compare the
`Minimum` it prints with the version your app sends. The usual causes are a cached
configuration that predates your `.env` change (`php artisan config:cache`), a
`*_MINIMUM` set to the version you are about to release rather than the oldest one you
still support, or the app sending its build number (`87`) in `X-App-Version`, which
parses as `87.0.0`. Compare the request with `curl -i` as in "Verify it works".

**Headers are missing behind a proxy or CDN.** Two different things can go wrong.
Request headers: some CDNs forward only the request headers you list. On Amazon
CloudFront, for example, `X-App-Version`, `X-App-Build` and `X-App-Platform` must be in
the origin request policy or the origin never sees them, and the middleware treats
the request as one without headers. Response caching: a CDN that caches API responses
by URL alone will hand a `426` or a `Deprecation` header to the wrong client. The
middleware sends `Vary: X-App-Version, X-App-Platform` for exactly this reason; make
sure your CDN honours `Vary` for these headers or, better, does not cache
authenticated API responses at all. The policy endpoint is safe to cache: its body
depends only on the `platform` query parameter (and it sends `Vary: X-App-Platform`
when it had to fall back to the header).

**Strict mode rejects web routes.** With `APP_VERSION_STRICT=true`, any request without
`X-App-Version` and `X-App-Platform` gets a `400`, and browsers never send them. The
middleware should only be on the routes your apps call: the `api` group or a route
group, never the `web` group or the global stack. If a partner or a monitoring probe
also calls the API without the headers, leave strict mode off; a request without
headers then passes through and is simply not counted.

**The policy endpoint returns 426.** It should not, even with the middleware on the
`api` group, because the route excludes it. If you see this, you have wrapped the
endpoint in your own middleware group that includes `app.version`; remove it there.

**An `InvalidPolicy` exception on every request.** The three versions are out of order
(`minimum` above `recommended`, or `recommended` above `latest`), a version is not a
valid semantic version, `deprecated_at` or `sunset` does not parse, `sunset` is earlier
than `deprecated_at`, or `store_url` is not an absolute URL.
The message names the platform and the key. This fails loudly instead of silently
blocking or unblocking everyone.

## Limitations

- Policies are per platform. There is no per-route, per-build-number or percentage
  rollout; if you need those, implement `PolicyResolver` and encode the rule there.
- The package does not version your API routes (`/api/v1`, `/api/v2`). It tells old apps
  to update; which routes they still get is yours to decide.
- Usage counters are approximate and live in the cache (see above). The event is the
  durable hook.
- `Deprecation` and `Sunset` are only as good as the dates you configure. Without
  `deprecated_at` no `Deprecation` header is sent at all; the `426` for versions below
  `minimum` is what enforces, the headers only inform.
- Only `X-App-Version` is compared. `X-App-Build` is parsed, attached to the request and
  logged, but never used to decide an update.

## Versioning policy

This package follows [Semantic Versioning](https://semver.org/). While the major version
is `0`, a minor release may change the PHP API; every such change is listed in
[CHANGELOG.md](CHANGELOG.md) under a heading that names it. The HTTP protocol (header
names, the JSON keys and their order, the `426` behaviour and the advisory headers) is
treated as stable from `0.1.0` and changes only in a major release, because apps already
installed on phones cannot be updated to follow it. New Laravel major versions are
supported in a minor release; dropping a PHP or Laravel version is a major release.

## Contributing

```sh
git clone https://github.com/puneetpals271/laravel-app-version.git
cd laravel-app-version
composer install
composer test
```

The suite uses PHPUnit and Orchestra Testbench and runs in about a second. CI runs it on
PHP 8.2, 8.3 and 8.4 against Laravel 12 and on PHP 8.3 and 8.4 against Laravel 13.
Please include a test with every change
in behaviour, keep the package free of runtime dependencies beyond the framework, and
open pull requests against `main`. Bugs go through the issue template; security
problems go to the address in [SECURITY.md](SECURITY.md), not the issue tracker.

## Background

The reasoning behind the policy shape, the `426` response and the advisory headers is in
[Laravel as a Backend for Mobile Apps (2026)](https://puneetpal.dev/insights/laravel-backend-for-mobile-apps-2026).

## License

MIT. See [LICENSE](LICENSE).
