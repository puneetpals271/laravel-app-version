<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Puneetpal\AppVersion\Console\PolicyCommand;
use Puneetpal\AppVersion\Console\ReportCommand;
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\Events\AppVersionSeen;
use Puneetpal\AppVersion\Http\Middleware\EnforceAppVersion;
use Puneetpal\AppVersion\Resolvers\ConfigPolicyResolver;
use Puneetpal\AppVersion\Usage\CacheUsageRecorder;

final class AppVersionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/app-version.php', 'app-version');

        $this->app->bind(PolicyResolver::class, function ($app): PolicyResolver {
            $resolver = $app['config']->get('app-version.resolver');

            if (is_string($resolver) && $resolver !== '') {
                return $app->make($resolver);
            }

            return new ConfigPolicyResolver($app['config']);
        });

        $this->app->singleton(AppVersion::class, fn ($app) => new AppVersion($app->make(PolicyResolver::class)));
        $this->app->singleton(CacheUsageRecorder::class);
    }

    public function boot(Router $router, Config $config, Dispatcher $events): void
    {
        $router->aliasMiddleware('app.version', EnforceAppVersion::class);

        Request::macro('appVersion', function (): ?ClientVersion {
            /** @var Request $this */
            $attached = $this->attributes->get(EnforceAppVersion::ATTRIBUTE);

            return $attached instanceof ClientVersion ? $attached : ClientVersion::tryFromRequest($this);
        });

        if ($config->get('app-version.route.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/app-version.php');
        }

        if ($config->get('app-version.usage.enabled', false) && $config->get('app-version.usage.record', true)) {
            $events->listen(AppVersionSeen::class, CacheUsageRecorder::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/app-version.php' => $this->app->configPath('app-version.php'),
            ], 'app-version-config');

            $this->commands([ReportCommand::class, PolicyCommand::class]);
        }
    }
}
