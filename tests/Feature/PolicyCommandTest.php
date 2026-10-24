<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Puneetpal\AppVersion\Tests\TestCase;

final class PolicyCommandTest extends TestCase
{
    #[Test]
    public function it_prints_every_configured_platform(): void
    {
        [$exitCode, $output] = $this->runArtisan('app-version:policy');

        $this->assertSame(0, $exitCode);

        foreach ([
            'ios', '3.0.0', '3.4.0', '3.4.1', self::IOS_STORE_URL, '(@1793491200)', 'Mon, 01 Mar 2027 00:00:00 GMT',
            'android', '2.0.0', '2.5.0', '2.5.3', self::ANDROID_STORE_URL, 'Please update to keep using the app.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
    }

    #[Test]
    public function it_prints_a_single_platform(): void
    {
        [$exitCode, $output] = $this->runArtisan('app-version:policy', ['platform' => 'android']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('android', $output);
        $this->assertStringContainsString(self::ANDROID_STORE_URL, $output);
        $this->assertStringNotContainsString('3.4.1', $output);
    }

    #[Test]
    public function an_unknown_platform_fails(): void
    {
        [$exitCode, $output] = $this->runArtisan('app-version:policy', ['platform' => 'web']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No policy for "web".', $output);
    }

    #[Test]
    public function a_broken_policy_is_reported_instead_of_crashing(): void
    {
        config()->set('app-version.platforms.ios.minimum', '9.0.0');

        [$exitCode, $output] = $this->runArtisan('app-version:policy');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('out of order', $output);
        // The healthy platform is still printed.
        $this->assertStringContainsString('android', $output);
        $this->assertStringContainsString('2.5.3', $output);
    }

    #[Test]
    public function it_fails_when_nothing_is_configured(): void
    {
        config()->set('app-version.platforms', []);

        [$exitCode, $output] = $this->runArtisan('app-version:policy');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No platforms are configured', $output);
    }
}
