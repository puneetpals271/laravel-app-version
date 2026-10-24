<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Usage;

use DateTimeInterface;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Carbon;
use Puneetpal\AppVersion\Events\AppVersionSeen;

/**
 * Counts requests per day, platform and version in the cache so that
 * app-version:report can answer "who still runs 2.x?" without a log pipeline.
 *
 * Layout, under config('app-version.usage.prefix'):
 *   {prefix}:{date}:platforms              list of platforms seen that day
 *   {prefix}:{date}:{platform}:versions    list of versions seen that day
 *   {prefix}:{date}:{platform}:{version}   request counter (atomic increment)
 *
 * Counters use the store's atomic increment. The two index lists are only
 * written the first time a value appears each day, under a cache lock when
 * the store supports one. On every other request the hot path is one add(),
 * one increment() and two reads of the index lists; nothing touches the
 * database.
 */
final class CacheUsageRecorder
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Config $config,
    ) {
    }

    public function handle(AppVersionSeen $event): void
    {
        $store = $this->store();
        $date = Carbon::now()->toDateString();
        $platform = $event->client->platform ?? 'unknown';
        $version = $event->client->version->toString();
        $expiresAt = Carbon::now()->addDays($this->retentionDays());

        $counter = $this->key($date, $platform, $version);
        $store->add($counter, 0, $expiresAt);
        $store->increment($counter);

        $this->remember($store, $this->key($date, 'platforms'), $platform, $expiresAt);
        $this->remember($store, $this->key($date, $platform, 'versions'), $version, $expiresAt);
    }

    public function store(): Cache
    {
        $name = $this->config->get('app-version.usage.store');

        return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
    }

    public function retentionDays(): int
    {
        return max(1, (int) $this->config->get('app-version.usage.retention_days', 90));
    }

    public function key(string ...$parts): string
    {
        $prefix = (string) $this->config->get('app-version.usage.prefix', 'app-version:usage');

        return $prefix.':'.implode(':', $parts);
    }

    private function remember(Cache $store, string $key, string $value, DateTimeInterface $expiresAt): void
    {
        $current = $store->get($key, []);

        if (is_array($current) && in_array($value, $current, true)) {
            return;
        }

        $write = function () use ($store, $key, $value, $expiresAt): void {
            $current = $store->get($key, []);
            $current = is_array($current) ? $current : [];

            if (! in_array($value, $current, true)) {
                $current[] = $value;
                $store->put($key, $current, $expiresAt);
            }
        };

        $underlying = $store->getStore();

        if (! $underlying instanceof LockProvider) {
            $write();

            return;
        }

        try {
            // Waiting happens at most once per new platform or version per day, and never longer than this.
            $underlying->lock($key.':lock', 5)->block(1, $write);
        } catch (LockTimeoutException) {
            // A lost index entry only hides a version from the report for the day; never fail the request.
            $write();
        }
    }
}
