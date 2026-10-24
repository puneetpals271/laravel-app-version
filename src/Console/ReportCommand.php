<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Console;

use Illuminate\Console\Command;
use Puneetpal\AppVersion\AppVersion;
use Puneetpal\AppVersion\UpdateRequirement;
use Puneetpal\AppVersion\Usage\UsageReport;
use Puneetpal\AppVersion\Version;

final class ReportCommand extends Command
{
    protected $signature = 'app-version:report
        {--days=7 : Number of days to include, today included}
        {--platform= : Limit the report to one platform}';

    protected $description = 'Summarise the app versions seen in requests and how each stands against the current policy';

    public function handle(UsageReport $report, AppVersion $appVersion): int
    {
        if (! $this->laravel['config']->get('app-version.usage.enabled', false)) {
            $this->components->warn('Usage recording is off. Set APP_VERSION_USAGE=true (app-version.usage.enabled) to start counting requests.');
        }

        $days = max(1, (int) $this->option('days'));
        $platformOption = $this->option('platform');
        $platform = is_string($platformOption) && $platformOption !== '' ? $platformOption : null;

        $summary = $report->summarise($days, $platform);

        if ($summary === []) {
            $this->components->info(sprintf('No app versions recorded in the last %d day(s).', $days));

            return self::SUCCESS;
        }

        foreach ($summary as $seenPlatform => $versions) {
            $policy = $appVersion->policy($seenPlatform);
            $total = array_sum($versions);
            $belowMinimum = 0;
            $belowRecommended = 0;
            $rows = [];

            foreach ($versions as $version => $count) {
                $parsed = Version::tryParse($version);
                $status = 'no policy';

                if ($policy !== null && $parsed !== null) {
                    $requirement = $policy->evaluate($parsed);
                    $status = match ($requirement) {
                        UpdateRequirement::Hard => 'below minimum',
                        UpdateRequirement::Soft => 'below recommended',
                        UpdateRequirement::None => 'current',
                    };
                    $belowMinimum += $requirement->isBlocking() ? $count : 0;
                    $belowRecommended += $requirement->isDeprecated() ? $count : 0;
                }

                $rows[] = [
                    $version,
                    number_format($count),
                    $total > 0 ? sprintf('%.1f%%', $count / $total * 100) : '0.0%',
                    $status,
                ];
            }

            $this->newLine();
            $this->components->twoColumnDetail(
                sprintf('<fg=green;options=bold>%s</>', $seenPlatform),
                sprintf('%s requests over %d day(s)', number_format($total), $days),
            );

            if ($policy !== null) {
                $this->components->twoColumnDetail('Policy', sprintf(
                    'minimum %s, recommended %s, latest %s',
                    $policy->minimum,
                    $policy->recommended,
                    $policy->latest,
                ));
                $this->components->twoColumnDetail('Below minimum', $this->share($belowMinimum, $total));
                $this->components->twoColumnDetail('Below recommended', $this->share($belowRecommended, $total));
            }

            $this->table(['Version', 'Requests', 'Share', 'Status'], $rows);
        }

        return self::SUCCESS;
    }

    private function share(int $count, int $total): string
    {
        if ($total === 0) {
            return '0 (0.0%)';
        }

        return sprintf('%s (%.1f%%)', number_format($count), $count / $total * 100);
    }
}
