<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Feature;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\AppVersion\Events\AppVersionSeen;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;
use Puneetpal\AppVersion\Tests\TestCase;
use Puneetpal\AppVersion\UpdateRequirement;

final class MiddlewareTest extends TestCase
{
    protected function enableUsage($app): void
    {
        $app['config']->set('app-version.usage.enabled', true);
        $app['config']->set('app-version.usage.record', false);
    }

    protected function disableContext($app): void
    {
        $app['config']->set('app-version.context', false);
    }

    #[Test]
    public function requests_without_headers_pass_through_untouched(): void
    {
        $response = $this->getJson('/api/ping');

        $response->assertOk()
            ->assertExactJson(['ok' => true])
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset');
    }

    #[Test]
    public function a_version_below_minimum_gets_426_with_the_policy_body(): void
    {
        $response = $this->withHeaders($this->appHeaders('2.9.9', 'ios', '87'))->getJson('/api/ping');

        $response->assertStatus(426)
            ->assertExactJson($this->iosPolicyJson())
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Deprecation', self::IOS_DEPRECATION)
            ->assertHeader('Sunset', 'Mon, 01 Mar 2027 00:00:00 GMT')
            ->assertHeader('Link', '<'.self::IOS_STORE_URL.'>; rel="alternate"');
    }

    #[Test]
    public function a_pre_release_of_the_minimum_is_still_below_it(): void
    {
        $this->withHeaders($this->appHeaders('3.0.0-beta.1'))->getJson('/api/ping')->assertStatus(426);
    }

    #[Test]
    public function a_version_below_recommended_is_served_with_deprecation_headers(): void
    {
        $response = $this->withHeaders($this->appHeaders('3.2.0'))->getJson('/api/ping');

        $response->assertOk()
            ->assertExactJson(['ok' => true])
            ->assertHeader('Deprecation', self::IOS_DEPRECATION)
            ->assertHeader('Sunset', 'Mon, 01 Mar 2027 00:00:00 GMT')
            ->assertHeader('Link', '<'.self::IOS_STORE_URL.'>; rel="alternate"');
    }

    #[Test]
    public function exactly_the_minimum_is_served_but_deprecated(): void
    {
        $this->withHeaders($this->appHeaders('3.0.0'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Deprecation', self::IOS_DEPRECATION);
    }

    #[Test]
    public function versions_at_or_above_recommended_get_no_deprecation_headers(): void
    {
        foreach (['3.4.0', '3.4.1', '3.4.1+200', '4.0.0'] as $version) {
            $this->withHeaders($this->appHeaders($version))->getJson('/api/ping')
                ->assertOk()
                ->assertExactJson(['ok' => true])
                ->assertHeaderMissing('Deprecation')
                ->assertHeaderMissing('Sunset')
                ->assertHeaderMissing('Link');
        }
    }

    #[Test]
    public function deprecation_and_sunset_are_omitted_when_the_platform_has_no_dates_but_link_is_still_sent(): void
    {
        $response = $this->withHeaders($this->appHeaders('2.2.0', 'android'))->getJson('/api/ping');

        $response->assertOk()
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset')
            ->assertHeader('Link', '<'.self::ANDROID_STORE_URL.'>; rel="alternate"')
            ->assertHeader('Vary', 'X-App-Version, X-App-Platform');
    }

    #[Test]
    public function a_sunset_earlier_than_deprecated_at_is_rejected_at_request_time(): void
    {
        config()->set('app-version.platforms.ios.deprecated_at', '2027-06-01');

        $this->withoutExceptionHandling();

        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('sunset date earlier than its deprecated_at date');

        $this->withHeaders($this->appHeaders('3.4.1'))->getJson('/api/ping');
    }

    #[Test]
    public function the_426_body_carries_the_platform_message(): void
    {
        $this->withHeaders($this->appHeaders('1.0.0', 'android'))->getJson('/api/ping')
            ->assertStatus(426)
            ->assertExactJson([
                'platform' => 'android',
                'minimum' => '2.0.0',
                'recommended' => '2.5.0',
                'latest' => '2.5.3',
                'store_url' => self::ANDROID_STORE_URL,
                'message' => 'Please update to keep using the app.',
            ]);
    }

    #[Test]
    public function the_platform_header_is_case_insensitive(): void
    {
        $this->withHeaders($this->appHeaders('2.9.9', 'iOS'))->getJson('/api/ping')->assertStatus(426);
    }

    #[Test]
    public function an_existing_link_header_is_kept_alongside_the_store_link(): void
    {
        $response = $this->withHeaders($this->appHeaders('3.2.0'))->getJson('/api/paged');

        $response->assertOk();
        $this->assertSame([
            '<https://api.example.com/orders?page=2>; rel="next"',
            '<'.self::IOS_STORE_URL.'>; rel="alternate"',
        ], $response->headers->all('Link'));
    }

    #[Test]
    public function the_parsed_client_is_attached_to_the_request(): void
    {
        $this->withHeaders($this->appHeaders('3.4', 'ios', '120'))->getJson('/api/whoami')
            ->assertOk()
            ->assertExactJson(['client' => ['version' => '3.4.0', 'build' => 120, 'platform' => 'ios']]);

        $this->flushHeaders()->getJson('/api/whoami')->assertOk()->assertExactJson(['client' => null]);
    }

    #[Test]
    public function the_request_macro_parses_lazily_outside_the_middleware(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'android'))->getJson('/api/plain')
            ->assertOk()
            ->assertExactJson(['client' => ['version' => '3.4.1', 'build' => null, 'platform' => 'android']]);

        $this->withHeaders(['X-App-Version' => 'garbage'])->getJson('/api/plain')
            ->assertOk()
            ->assertExactJson(['client' => null]);
    }

    #[Test]
    public function a_malformed_version_header_is_a_400(): void
    {
        $this->withHeaders($this->appHeaders('latest'))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_app_version')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'X-App-Version'));
    }

    #[Test]
    public function a_malformed_build_header_is_a_400(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios', 'build-120'))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_app_build');
    }

    #[Test]
    public function a_malformed_platform_header_is_a_400(): void
    {
        foreach (['i/os', 'ios.minimum', str_repeat('a', 40)] as $platform) {
            $this->withHeaders($this->appHeaders('3.4.1', $platform))->getJson('/api/ping')
                ->assertStatus(400)
                ->assertJsonPath('error', 'invalid_app_platform');
        }
    }

    #[Test]
    public function a_header_that_is_not_valid_utf8_is_a_400_not_a_500(): void
    {
        $this->withHeaders($this->appHeaders("3.\xff.1"))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_app_version')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, '"3.?.1"'));

        $this->withHeaders($this->appHeaders('3.4.1', "ios\xff"))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_app_platform');
    }

    #[Test]
    public function the_426_body_does_not_escape_slashes_in_the_store_url(): void
    {
        $content = $this->withHeaders($this->appHeaders('2.9.9'))->getJson('/api/ping')
            ->assertStatus(426)
            ->getContent();

        $this->assertStringContainsString('"store_url":"'.self::IOS_STORE_URL.'"', (string) $content);
    }

    #[Test]
    public function an_unknown_platform_passes_through_when_not_strict(): void
    {
        $this->withHeaders($this->appHeaders('0.0.1', 'web'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeaderMissing('Deprecation');
    }

    #[Test]
    public function a_missing_platform_passes_through_when_not_strict(): void
    {
        $this->withHeaders($this->appHeaders('0.0.1', null))->getJson('/api/ping')
            ->assertOk()
            ->assertHeaderMissing('Deprecation');
    }

    #[Test]
    public function strict_mode_rejects_requests_without_headers(): void
    {
        config()->set('app-version.strict', true);

        $this->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'app_version_headers_required');
    }

    #[Test]
    public function strict_mode_rejects_a_missing_platform(): void
    {
        config()->set('app-version.strict', true);

        $this->withHeaders($this->appHeaders('3.4.1', null))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'app_platform_required');
    }

    #[Test]
    public function strict_mode_rejects_an_unknown_platform(): void
    {
        config()->set('app-version.strict', true);

        $this->withHeaders($this->appHeaders('3.4.1', 'web'))->getJson('/api/ping')
            ->assertStatus(400)
            ->assertJsonPath('error', 'unknown_app_platform');
    }

    #[Test]
    public function strict_mode_still_serves_well_formed_requests(): void
    {
        config()->set('app-version.strict', true);

        $this->withHeaders($this->appHeaders('3.4.1'))->getJson('/api/ping')->assertOk();
        $this->withHeaders($this->appHeaders('2.0.0'))->getJson('/api/ping')->assertStatus(426);
    }

    #[Test]
    public function client_details_are_added_to_the_log_context(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios', '120'))->getJson('/api/ping')->assertOk();

        $this->assertSame('3.4.1', Context::get('app_version'));
        $this->assertSame(120, Context::get('app_build'));
        $this->assertSame('ios', Context::get('app_platform'));
    }

    #[Test]
    #[DefineEnvironment('disableContext')]
    public function log_context_can_be_turned_off(): void
    {
        $this->withHeaders($this->appHeaders('3.4.1', 'ios', '120'))->getJson('/api/ping')->assertOk();

        $this->assertFalse(Context::has('app_version'));
    }

    #[Test]
    public function no_event_is_dispatched_when_usage_recording_is_off(): void
    {
        Event::fake([AppVersionSeen::class]);

        $this->withHeaders($this->appHeaders('3.2.0'))->getJson('/api/ping')->assertOk();

        Event::assertNotDispatched(AppVersionSeen::class);
    }

    #[Test]
    #[DefineEnvironment('enableUsage')]
    public function an_event_is_dispatched_for_every_request_with_headers(): void
    {
        Event::fake([AppVersionSeen::class]);

        $this->withHeaders($this->appHeaders('3.2.0', 'ios', '120'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->withHeaders($this->appHeaders('1.0.0', 'ios'))->getJson('/api/ping')->assertStatus(426);
        $this->flushHeaders()->withHeaders($this->appHeaders('9.9.9', 'web'))->getJson('/api/ping')->assertOk();
        $this->flushHeaders()->getJson('/api/ping')->assertOk();

        Event::assertDispatchedTimes(AppVersionSeen::class, 3);

        Event::assertDispatched(AppVersionSeen::class, fn (AppVersionSeen $event) => $event->requirement === UpdateRequirement::Soft
            && $event->client->version->toString() === '3.2.0'
            && $event->client->build === 120
            && $event->policy?->platform === 'ios'
            && $event->path === 'api/ping');

        Event::assertDispatched(AppVersionSeen::class, fn (AppVersionSeen $event) => $event->requirement === UpdateRequirement::Hard
            && $event->client->version->toString() === '1.0.0');

        Event::assertDispatched(AppVersionSeen::class, fn (AppVersionSeen $event) => $event->requirement === UpdateRequirement::None
            && $event->client->platform === 'web'
            && $event->policy === null);
    }

    #[Test]
    public function a_misconfigured_policy_fails_loudly_instead_of_locking_users_out(): void
    {
        // The config resolver reads live configuration, so a policy broken at runtime is caught too.
        config()->set('app-version.platforms.ios', ['minimum' => '4.0.0', 'recommended' => '3.4.0', 'latest' => '3.4.1']);

        $this->withoutExceptionHandling();

        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('out of order');

        $this->withHeaders($this->appHeaders('3.4.1'))->getJson('/api/ping');
    }

    #[Test]
    public function responses_shaped_by_the_policy_vary_on_the_client_headers(): void
    {
        $this->withHeaders($this->appHeaders('2.9.9'))->getJson('/api/ping')
            ->assertStatus(426)
            ->assertHeader('Vary', 'X-App-Version, X-App-Platform');

        $this->flushHeaders()->withHeaders($this->appHeaders('3.2.0'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Vary', 'X-App-Version, X-App-Platform');

        $this->flushHeaders()->withHeaders($this->appHeaders('3.4.1'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Vary', 'X-App-Version, X-App-Platform');

        $this->flushHeaders()->getJson('/api/ping')->assertOk()->assertHeaderMissing('Vary');
    }

    #[Test]
    public function an_existing_vary_header_is_extended_not_replaced(): void
    {
        $response = $this->withHeaders($this->appHeaders('3.4.1'))->getJson('/api/varied');

        $response->assertOk();
        $this->assertSame(['Accept-Encoding', 'X-App-Version, X-App-Platform'], $response->headers->all('Vary'));
    }
}
