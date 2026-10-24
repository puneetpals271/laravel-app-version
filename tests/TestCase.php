<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use Puneetpal\AppVersion\AppVersionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const IOS_STORE_URL = 'https://apps.apple.com/app/id000000000';

    protected const ANDROID_STORE_URL = 'https://play.google.com/store/apps/details?id=com.example.app';

    /** 2026-11-01T00:00:00Z as an RFC 9745 Structured Fields date. */
    protected const IOS_DEPRECATION = '@1793491200';

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AppVersionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app-version.platforms', [
            'ios' => [
                'minimum' => '3.0.0',
                'recommended' => '3.4.0',
                'latest' => '3.4.1',
                'store_url' => self::IOS_STORE_URL,
                'deprecated_at' => '2026-11-01',
                'sunset' => '2027-03-01',
                'message' => null,
            ],
            'android' => [
                'minimum' => '2.0.0',
                'recommended' => '2.5.0',
                'latest' => '2.5.3',
                'store_url' => self::ANDROID_STORE_URL,
                'sunset' => null,
                'message' => 'Please update to keep using the app.',
            ],
        ]);
    }

    protected function defineRoutes($router): void
    {
        /** @var Router $router */
        $router->middleware('app.version')->group(function (Router $router): void {
            $router->get('/api/ping', fn () => response()->json(['ok' => true]));

            $router->get('/api/paged', fn () => response()
                ->json(['data' => []])
                ->header('Link', '<https://api.example.com/orders?page=2>; rel="next"'));

            $router->get('/api/whoami', fn (Request $request) => response()->json([
                'client' => $request->appVersion()?->toArray(),
            ]));

            $router->get('/api/varied', fn () => response()
                ->json(['ok' => true])
                ->header('Vary', 'Accept-Encoding'));
        });

        $router->get('/api/plain', fn (Request $request) => response()->json([
            'client' => $request->appVersion()?->toArray(),
        ]));
    }

    /**
     * Runs an artisan command and returns its exit code and complete output. Output
     * is matched as a whole because console components may write a line in pieces.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: string}
     */
    protected function runArtisan(string $command, array $parameters = []): array
    {
        $exitCode = Artisan::call($command, $parameters);

        return [$exitCode, Artisan::output()];
    }

    /**
     * @return array<string, string>
     */
    protected function appHeaders(string $version, ?string $platform = 'ios', ?string $build = null): array
    {
        $headers = ['X-App-Version' => $version];

        if ($platform !== null) {
            $headers['X-App-Platform'] = $platform;
        }

        if ($build !== null) {
            $headers['X-App-Build'] = $build;
        }

        return $headers;
    }

    /**
     * @return array{platform: string, minimum: string, recommended: string, latest: string, store_url: string|null, message: string|null}
     */
    protected function iosPolicyJson(): array
    {
        return [
            'platform' => 'ios',
            'minimum' => '3.0.0',
            'recommended' => '3.4.0',
            'latest' => '3.4.1',
            'store_url' => self::IOS_STORE_URL,
            'message' => null,
        ];
    }
}
