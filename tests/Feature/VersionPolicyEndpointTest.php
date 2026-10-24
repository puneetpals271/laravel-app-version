<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Feature;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\AppVersion\Tests\TestCase;

final class VersionPolicyEndpointTest extends TestCase
{
    protected function disableRoute($app): void
    {
        $app['config']->set('app-version.route.enabled', false);
    }

    protected function customPrefix($app): void
    {
        $app['config']->set('app-version.route.prefix', 'mobile/config');
        $app['config']->set('app-version.route.middleware', []);
    }

    protected function noCaching($app): void
    {
        $app['config']->set('app-version.route.cache_max_age', 0);
    }

    protected function enforcingGroup($app): void
    {
        // Simulates an application that appended the middleware to the group this route uses.
        $app['config']->set('app-version.route.middleware', ['app.version']);
    }

    #[Test]
    #[DefineEnvironment('enforcingGroup')]
    public function a_hard_blocked_client_can_read_the_policy_even_when_its_route_group_enforces(): void
    {
        $this->withHeaders($this->appHeaders('1.0.0', 'ios'))->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertExactJson($this->iosPolicyJson())
            ->assertHeaderMissing('Deprecation');
    }

    #[Test]
    public function the_response_varies_on_the_platform_header_only_when_it_was_used(): void
    {
        $this->withHeaders(['X-App-Platform' => 'android'])->getJson('/app/version-policy')
            ->assertOk()
            ->assertHeader('Vary', 'X-App-Platform');

        $this->flushHeaders()->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertHeaderMissing('Vary');
    }

    #[Test]
    public function it_returns_the_policy_for_the_requested_platform(): void
    {
        $response = $this->getJson('/app/version-policy?platform=ios');

        $response->assertOk()
            ->assertExactJson($this->iosPolicyJson())
            ->assertHeaderMissing('Deprecation');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
    }

    #[Test]
    public function the_platform_query_is_case_insensitive(): void
    {
        $this->getJson('/app/version-policy?platform=iOS')->assertOk()->assertJsonPath('platform', 'ios');
    }

    #[Test]
    public function it_falls_back_to_the_platform_header(): void
    {
        $this->withHeaders(['X-App-Platform' => 'android'])->getJson('/app/version-policy')
            ->assertOk()
            ->assertJsonPath('platform', 'android')
            ->assertJsonPath('minimum', '2.0.0')
            ->assertJsonPath('message', 'Please update to keep using the app.');
    }

    #[Test]
    public function the_query_wins_over_the_header(): void
    {
        $this->withHeaders(['X-App-Platform' => 'android'])->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertJsonPath('platform', 'ios');
    }

    #[Test]
    public function a_missing_platform_is_a_400(): void
    {
        $this->getJson('/app/version-policy')
            ->assertStatus(400)
            ->assertJsonPath('error', 'platform_required');

        $this->getJson('/app/version-policy?platform=')
            ->assertStatus(400)
            ->assertJsonPath('error', 'platform_required');
    }

    #[Test]
    public function an_unknown_platform_is_a_404(): void
    {
        $this->getJson('/app/version-policy?platform=web')
            ->assertStatus(404)
            ->assertJsonPath('error', 'unknown_app_platform');
    }

    #[Test]
    public function a_malformed_platform_is_a_400_and_is_not_echoed_back(): void
    {
        foreach (['i/os', 'ios.minimum', "ios\xff", str_repeat('a', 5000)] as $platform) {
            $response = $this->getJson('/app/version-policy?platform='.urlencode($platform));

            $response->assertStatus(400)->assertJsonPath('error', 'invalid_app_platform');
            $this->assertLessThan(300, strlen((string) $response->getContent()));
        }

        $this->withHeaders(['X-App-Platform' => 'i/os'])->getJson('/app/version-policy')
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_app_platform')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'X-App-Platform'));
    }

    #[Test]
    public function the_body_does_not_escape_slashes_in_the_store_url(): void
    {
        $content = $this->getJson('/app/version-policy?platform=ios')->assertOk()->getContent();

        $this->assertStringContainsString('"store_url":"'.self::IOS_STORE_URL.'"', (string) $content);
    }

    #[Test]
    public function a_hard_blocked_client_can_still_read_the_policy(): void
    {
        $this->withHeaders($this->appHeaders('1.0.0', 'ios'))->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertExactJson($this->iosPolicyJson());
    }

    #[Test]
    public function the_route_is_named(): void
    {
        $this->assertSame('http://localhost/app/version-policy?platform=ios', route('app-version.policy', ['platform' => 'ios']));
    }

    #[Test]
    #[DefineEnvironment('disableRoute')]
    public function the_route_can_be_disabled(): void
    {
        $this->getJson('/app/version-policy?platform=ios')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('customPrefix')]
    public function the_prefix_is_configurable(): void
    {
        $this->getJson('/mobile/config/version-policy?platform=ios')->assertOk()->assertExactJson($this->iosPolicyJson());
        $this->getJson('/app/version-policy?platform=ios')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('noCaching')]
    public function caching_can_be_turned_off(): void
    {
        $this->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
