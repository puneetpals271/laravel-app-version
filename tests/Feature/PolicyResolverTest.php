<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Feature;

use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use Puneetpal\AppVersion\AppVersion;
use Puneetpal\AppVersion\Contracts\PolicyResolver;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;
use Puneetpal\AppVersion\Facades\AppVersion as AppVersionFacade;
use Puneetpal\AppVersion\Resolvers\CallablePolicyResolver;
use Puneetpal\AppVersion\Resolvers\ConfigPolicyResolver;
use Puneetpal\AppVersion\Tests\Fixtures\TableResolver;
use Puneetpal\AppVersion\Tests\TestCase;
use Puneetpal\AppVersion\UpdateRequirement;
use Puneetpal\AppVersion\Version;
use Puneetpal\AppVersion\VersionPolicy;

final class PolicyResolverTest extends TestCase
{
    protected function useTableResolver($app): void
    {
        $app['config']->set('app-version.resolver', TableResolver::class);
    }

    #[Test]
    public function the_config_resolver_is_the_default(): void
    {
        $this->assertInstanceOf(ConfigPolicyResolver::class, $this->app->make(PolicyResolver::class));
        $this->assertInstanceOf(ConfigPolicyResolver::class, AppVersionFacade::resolver());
    }

    #[Test]
    public function the_config_resolver_returns_null_for_platforms_that_are_not_configured(): void
    {
        $this->assertNull(AppVersionFacade::policy('web'));
        $this->assertNull(AppVersionFacade::policy(''));
        $this->assertNull(AppVersionFacade::policy('   '));
        $this->assertSame('ios', AppVersionFacade::policy(' iOS ')?->platform);
    }

    #[Test]
    #[DefineEnvironment('useTableResolver')]
    public function a_resolver_class_named_in_config_replaces_the_config_resolver(): void
    {
        $this->assertInstanceOf(TableResolver::class, AppVersionFacade::resolver());

        $this->withHeaders($this->appHeaders('6.9.0', 'ios'))->getJson('/api/ping')
            ->assertStatus(426)
            ->assertJsonPath('minimum', '7.0.0')
            ->assertJsonPath('message', 'From the table');

        // The table knows nothing about android, so android requests pass through.
        $this->flushHeaders()->withHeaders($this->appHeaders('0.0.1', 'android'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeaderMissing('Deprecation');

        $this->getJson('/app/version-policy?platform=android')->assertNotFound();
    }

    #[Test]
    public function a_closure_resolver_may_return_a_policy_instance(): void
    {
        AppVersionFacade::resolveUsing(fn (string $platform) => new VersionPolicy(
            $platform,
            Version::parse('5.0.0'),
            Version::parse('5.0.0'),
            Version::parse('5.1.0'),
        ));

        $this->assertInstanceOf(CallablePolicyResolver::class, AppVersionFacade::resolver());

        $this->withHeaders($this->appHeaders('4.9.9', 'ios'))->getJson('/api/ping')
            ->assertStatus(426)
            ->assertExactJson([
                'platform' => 'ios',
                'minimum' => '5.0.0',
                'recommended' => '5.0.0',
                'latest' => '5.1.0',
                'store_url' => null,
                'message' => null,
            ])
            ->assertHeaderMissing('Deprecation')
            ->assertHeaderMissing('Sunset')
            ->assertHeaderMissing('Link');
    }

    #[Test]
    public function a_closure_resolver_may_return_the_config_array_shape(): void
    {
        AppVersionFacade::resolveUsing(fn (string $platform) => match ($platform) {
            'ios' => ['minimum' => '8.0.0', 'recommended' => '8.2.0', 'store_url' => 'https://apps.apple.com/app/id222222222', 'deprecated_at' => '2026-11-01'],
            default => null,
        });

        $this->withHeaders($this->appHeaders('8.1.0', 'ios'))->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Deprecation', self::IOS_DEPRECATION)
            ->assertHeader('Link', '<https://apps.apple.com/app/id222222222>; rel="alternate"');

        $this->getJson('/app/version-policy?platform=ios')
            ->assertOk()
            ->assertJsonPath('latest', '8.2.0');

        $this->getJson('/app/version-policy?platform=android')->assertNotFound();
    }

    #[Test]
    public function a_closure_resolver_may_return_null_or_an_empty_array_for_unknown_platforms(): void
    {
        $resolver = new CallablePolicyResolver(fn (string $platform) => $platform === 'ios' ? [] : null);

        $this->assertNull($resolver->resolve('ios'));
        $this->assertNull($resolver->resolve('android'));
    }

    #[Test]
    public function a_closure_resolver_that_returns_something_else_is_rejected(): void
    {
        $resolver = new CallablePolicyResolver(fn (string $platform) => '3.0.0');

        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('must return');

        $resolver->resolve('ios');
    }

    #[Test]
    public function a_resolver_instance_can_be_set_directly(): void
    {
        $service = $this->app->make(AppVersion::class);
        $returned = $service->resolveUsing(new TableResolver);

        $this->assertSame($service, $returned);
        $this->assertSame(UpdateRequirement::Hard, $service->evaluate(
            $service->client($this->requestWithHeaders('6.0.0', 'ios')) ?? $this->fail('Expected a client.'),
        ));
        $this->assertSame(UpdateRequirement::None, $service->evaluate(
            $service->client($this->requestWithHeaders('1.0.0', 'android')) ?? $this->fail('Expected a client.'),
        ));
    }

    #[Test]
    public function evaluating_a_client_without_a_platform_requires_nothing(): void
    {
        $service = $this->app->make(AppVersion::class);
        $client = $service->client($this->requestWithHeaders('0.0.1', null));

        $this->assertNotNull($client);
        $this->assertSame(UpdateRequirement::None, $service->evaluate($client));
        $this->assertNull($service->client($this->requestWithHeaders(null, 'ios')));
    }

    private function requestWithHeaders(?string $version, ?string $platform): \Illuminate\Http\Request
    {
        $server = [];

        if ($version !== null) {
            $server['HTTP_X_APP_VERSION'] = $version;
        }

        if ($platform !== null) {
            $server['HTTP_X_APP_PLATFORM'] = $platform;
        }

        return \Illuminate\Http\Request::create('/api/ping', 'GET', [], [], [], $server);
    }
}
