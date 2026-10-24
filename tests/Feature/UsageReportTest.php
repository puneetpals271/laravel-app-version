<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\AppVersion\Tests\TestCase;
use Puneetpal\AppVersion\Usage\CacheUsageRecorder;
use Puneetpal\AppVersion\Usage\UsageReport;

final class UsageReportTest extends TestCase
{
    protected function enableRecording($app): void
    {
        $app['config']->set('app-version.usage.enabled', true);
        $app['config']->set('app-version.usage.record', true);
        $app['config']->set('app-version.usage.retention_days', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function requests_are_counted_per_day_platform_and_version(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios'))->getJson('/api/ping')->assertOk();
        $this->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('3.2.0', 'ios'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('2.9.9', 'ios'))->getJson('/api/ping')->assertStatus(426);
        $this->flushHeaders()->withHeaders($this->appHeaders('2.5.3', 'android'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->getJson('/api/ping')->assertOk();

        $summary = $this->app->make(UsageReport::class)->summarise(7);

        $this->assertSame([
            'android' => ['2.5.3' => 1],
            'ios' => ['3.4.1' => 2, '3.2.0' => 1, '2.9.9' => 1],
        ], $summary);

        $this->assertSame(['ios' => ['3.4.1' => 2, '3.2.0' => 1, '2.9.9' => 1]], $this->app->make(UsageReport::class)->summarise(7, 'iOS'));
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function the_window_sums_across_days_and_excludes_older_ones(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->withHeaders($this->appHeaders('3.0.0', 'ios'))->getJson('/api/ping')->assertOk();

        Carbon::setTestNow('2026-10-23 12:00:00');
        $this->getJson('/api/ping')->assertOk();

        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->getJson('/api/ping')->assertOk();

        $report = $this->app->make(UsageReport::class);

        $this->assertSame(['ios' => ['3.0.0' => 3]], $report->summarise(7));
        $this->assertSame(['ios' => ['3.0.0' => 2]], $report->summarise(2));
        $this->assertSame(['ios' => ['3.0.0' => 1]], $report->summarise(1));
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function counters_expire_with_the_retention_period(): void
    {
        Carbon::setTestNow('2026-10-24 12:00:00');
        $this->withHeaders($this->appHeaders('3.4.1', 'ios'))->getJson('/api/ping')->assertOk();

        $recorder = $this->app->make(CacheUsageRecorder::class);
        $this->assertSame(1, Cache::get($recorder->key('2026-10-24', 'ios', '3.4.1')));
        $this->assertSame(['ios'], Cache::get($recorder->key('2026-10-24', 'platforms')));
        $this->assertSame(['3.4.1'], Cache::get($recorder->key('2026-10-24', 'ios', 'versions')));

        Carbon::setTestNow('2026-11-24 12:00:01');

        $this->assertNull(Cache::get($recorder->key('2026-10-24', 'ios', '3.4.1')));
        $this->assertSame([], $this->app->make(UsageReport::class)->summarise(90));
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function a_client_without_a_platform_is_counted_under_unknown(): void
    {
        $this->withHeaders($this->appHeaders('1.2.3', null))->getJson('/api/ping')->assertOk();

        $this->assertSame(['unknown' => ['1.2.3' => 1]], $this->app->make(UsageReport::class)->summarise());
    }

    #[Test]
    public function nothing_is_recorded_when_usage_is_off(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios'))->getJson('/api/ping')->assertOk();

        $this->assertSame([], $this->app->make(UsageReport::class)->summarise());
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function the_report_command_shows_each_version_against_the_policy(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios'))->getJson('/api/ping')->assertOk();
        $this->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('3.2.0', 'ios'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('2.9.9', 'ios'))->getJson('/api/ping')->assertStatus(426);

        [$exitCode, $output] = $this->runArtisan('app-version:report', ['--days' => 7]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('4 requests over 7 day(s)', $output);
        $this->assertStringContainsString('minimum 3.0.0, recommended 3.4.0, latest 3.4.1', $output);
        $this->assertStringContainsString('1 (25.0%)', $output);
        $this->assertStringContainsString('2 (50.0%)', $output);
        $this->assertMatchesRegularExpression('/\| 3\.4\.1\s+\| 2\s+\| 50\.0%\s+\| current\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| 3\.2\.0\s+\| 1\s+\| 25\.0%\s+\| below recommended\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\| 2\.9\.9\s+\| 1\s+\| 25\.0%\s+\| below minimum\s+\|/', $output);
        $this->assertStringNotContainsString('Usage recording is off', $output);
    }

    #[Test]
    #[DefineEnvironment('enableRecording')]
    public function the_report_command_can_be_limited_to_one_platform(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('2.5.3', 'android'))->getJson('/api/ping')->assertOk();

        [$exitCode, $output] = $this->runArtisan('app-version:report', ['--platform' => 'android']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('android', $output);
        $this->assertStringContainsString('2.5.3', $output);
        $this->assertStringNotContainsString('3.4.1', $output);
    }

    #[Test]
    public function the_report_command_warns_when_recording_is_off_and_reports_no_data(): void
    {
        [$exitCode, $output] = $this->runArtisan('app-version:report');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage recording is off', $output);
        $this->assertStringContainsString('No app versions recorded in the last 7 day(s).', $output);
    }
}
