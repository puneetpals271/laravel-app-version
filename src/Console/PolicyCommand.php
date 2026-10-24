<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Console;

use Illuminate\Console\Command;
use Puneetpal\AppVersion\AppVersion;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;

final class PolicyCommand extends Command
{
    protected $signature = 'app-version:policy
        {platform? : Show one platform; defaults to every platform in config}';

    protected $description = 'Show the app version policy currently being served';

    public function handle(AppVersion $appVersion): int
    {
        $argument = $this->argument('platform');
        $platforms = is_string($argument) && $argument !== ''
            ? [$argument]
            : array_keys((array) $this->laravel['config']->get('app-version.platforms', []));

        if ($platforms === []) {
            $this->components->warn('No platforms are configured under app-version.platforms. Pass a platform name to query a custom resolver.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($platforms as $platform) {
            try {
                $policy = $appVersion->policy((string) $platform);
            } catch (InvalidPolicy $e) {
                $this->components->error($e->getMessage());
                $failed = true;

                continue;
            }

            if ($policy === null) {
                $this->components->warn(sprintf('No policy for "%s".', $platform));
                $failed = true;

                continue;
            }

            $this->newLine();
            $this->components->twoColumnDetail(sprintf('<fg=green;options=bold>%s</>', $policy->platform), '');
            $this->components->twoColumnDetail('Minimum', $policy->minimum->toString());
            $this->components->twoColumnDetail('Recommended', $policy->recommended->toString());
            $this->components->twoColumnDetail('Latest', $policy->latest->toString());
            $this->components->twoColumnDetail('Store URL', $policy->storeUrl ?? '<fg=gray>none</>');
            $this->components->twoColumnDetail('Deprecated at', $policy->deprecatedAt !== null
                ? sprintf('%s (@%d)', $policy->deprecatedAt->toRfc7231String(), $policy->deprecatedAt->getTimestamp())
                : '<fg=gray>none</>');
            $this->components->twoColumnDetail('Sunset', $policy->sunset?->toRfc7231String() ?? '<fg=gray>none</>');
            $this->components->twoColumnDetail('Message', $policy->message ?? '<fg=gray>none</>');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
