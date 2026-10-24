<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Usage;

use Illuminate\Support\Carbon;
use Puneetpal\AppVersion\Version;

/**
 * Reads what CacheUsageRecorder wrote and sums it over a window of days.
 */
final class UsageReport
{
    public function __construct(private readonly CacheUsageRecorder $recorder)
    {
    }

    /**
     * Requests per platform and version over the last $days days, today included.
     * Versions are sorted from newest to oldest.
     *
     * @return array<string, array<string, int>>
     */
    public function summarise(int $days = 7, ?string $platform = null): array
    {
        $days = max(1, $days);
        $store = $this->recorder->store();
        $platform = $platform === null ? null : strtolower(trim($platform));
        $totals = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = Carbon::now()->subDays($offset)->toDateString();
            $platforms = $store->get($this->recorder->key($date, 'platforms'), []);

            if (! is_array($platforms)) {
                continue;
            }

            foreach ($platforms as $seenPlatform) {
                if (! is_string($seenPlatform) || ($platform !== null && $seenPlatform !== $platform)) {
                    continue;
                }

                $versions = $store->get($this->recorder->key($date, $seenPlatform, 'versions'), []);

                if (! is_array($versions)) {
                    continue;
                }

                foreach ($versions as $version) {
                    if (! is_string($version)) {
                        continue;
                    }

                    $count = (int) $store->get($this->recorder->key($date, $seenPlatform, $version), 0);
                    $totals[$seenPlatform][$version] = ($totals[$seenPlatform][$version] ?? 0) + $count;
                }
            }
        }

        ksort($totals);

        foreach ($totals as &$versions) {
            uksort($versions, function (string $a, string $b): int {
                $va = Version::tryParse($a);
                $vb = Version::tryParse($b);

                if ($va === null || $vb === null) {
                    return strcmp($b, $a);
                }

                return $vb->compare($va);
            });
        }

        return $totals;
    }
}
