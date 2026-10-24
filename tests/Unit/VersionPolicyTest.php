<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Puneetpal\AppVersion\Exceptions\InvalidPolicy;
use Puneetpal\AppVersion\UpdateRequirement;
use Puneetpal\AppVersion\Version;
use Puneetpal\AppVersion\VersionPolicy;

final class VersionPolicyTest extends TestCase
{
    private function policy(): VersionPolicy
    {
        return VersionPolicy::fromArray('ios', [
            'minimum' => '3.0.0',
            'recommended' => '3.4.0',
            'latest' => '3.4.1',
            'store_url' => 'https://apps.apple.com/app/id000000000',
            'deprecated_at' => '2026-11-01',
            'sunset' => '2027-03-01',
            'message' => null,
        ]);
    }

    /**
     * @return iterable<string, array{string, UpdateRequirement}>
     */
    public static function evaluations(): iterable
    {
        yield 'far below minimum' => ['1.0.0', UpdateRequirement::Hard];
        yield 'just below minimum' => ['2.9.9', UpdateRequirement::Hard];
        yield 'pre-release of minimum' => ['3.0.0-beta.1', UpdateRequirement::Hard];
        yield 'exactly minimum' => ['3.0.0', UpdateRequirement::Soft];
        yield 'between minimum and recommended' => ['3.2.0', UpdateRequirement::Soft];
        yield 'just below recommended' => ['3.3.99', UpdateRequirement::Soft];
        yield 'exactly recommended' => ['3.4.0', UpdateRequirement::None];
        yield 'latest' => ['3.4.1', UpdateRequirement::None];
        yield 'ahead of latest (TestFlight build)' => ['3.5.0', UpdateRequirement::None];
    }

    #[Test]
    #[DataProvider('evaluations')]
    public function it_evaluates_each_policy_branch(string $version, UpdateRequirement $expected): void
    {
        $this->assertSame($expected, $this->policy()->evaluate(Version::parse($version)));
    }

    #[Test]
    public function requirement_flags(): void
    {
        $this->assertTrue(UpdateRequirement::Hard->isBlocking());
        $this->assertTrue(UpdateRequirement::Hard->isDeprecated());
        $this->assertFalse(UpdateRequirement::Soft->isBlocking());
        $this->assertTrue(UpdateRequirement::Soft->isDeprecated());
        $this->assertFalse(UpdateRequirement::None->isBlocking());
        $this->assertFalse(UpdateRequirement::None->isDeprecated());
    }

    #[Test]
    public function to_array_is_exactly_the_protocol_body(): void
    {
        $expected = [
            'platform' => 'ios',
            'minimum' => '3.0.0',
            'recommended' => '3.4.0',
            'latest' => '3.4.1',
            'store_url' => 'https://apps.apple.com/app/id000000000',
            'message' => null,
        ];

        $this->assertSame($expected, $this->policy()->toArray());
        $this->assertSame($expected, json_decode(json_encode($this->policy()), true));
        $this->assertSame(array_keys($expected), array_keys($this->policy()->toArray()), 'Key order is part of the protocol.');
    }

    #[Test]
    public function deprecation_headers_include_the_rfc_9745_date_sunset_and_link_when_configured(): void
    {
        $this->assertSame([
            'Deprecation' => '@1793491200',
            'Sunset' => 'Mon, 01 Mar 2027 00:00:00 GMT',
            'Link' => '<https://apps.apple.com/app/id000000000>; rel="alternate"',
        ], $this->policy()->deprecationHeaders());
    }

    #[Test]
    public function the_deprecation_header_is_omitted_when_no_deprecated_at_is_configured(): void
    {
        $policy = VersionPolicy::fromArray('android', [
            'minimum' => '2.0.0',
            'recommended' => '2.5.0',
            'store_url' => 'https://play.google.com/store/apps/details?id=com.example.app',
            'sunset' => '2027-03-01',
        ]);

        $this->assertSame([
            'Sunset' => 'Mon, 01 Mar 2027 00:00:00 GMT',
            'Link' => '<https://play.google.com/store/apps/details?id=com.example.app>; rel="alternate"',
        ], $policy->deprecationHeaders());
        $this->assertNull($policy->deprecatedAt);
    }

    #[Test]
    public function no_headers_are_produced_when_nothing_is_configured(): void
    {
        $policy = VersionPolicy::fromArray('android', ['minimum' => '2.0.0', 'recommended' => '2.5.0']);

        $this->assertSame([], $policy->deprecationHeaders());
    }

    #[Test]
    public function deprecated_at_is_sent_as_a_unix_timestamp_regardless_of_time_zone(): void
    {
        $policy = VersionPolicy::fromArray('ios', [
            'minimum' => '3.0.0',
            'deprecated_at' => '2026-11-01T00:00:00-05:00',
        ]);

        $this->assertSame('@'.(1793491200 + 5 * 3600), $policy->deprecationHeaders()['Deprecation']);
        $this->assertSame(1793491200 + 5 * 3600, $policy->deprecatedAt?->getTimestamp());
    }

    #[Test]
    public function sunset_accepts_a_datetime_instance_and_is_rendered_in_gmt(): void
    {
        $policy = new VersionPolicy(
            'ios',
            Version::parse('1.0.0'),
            Version::parse('1.0.0'),
            Version::parse('1.0.0'),
            deprecatedAt: new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')),
            sunset: new DateTimeImmutable('2027-03-01 09:30:00', new DateTimeZone('America/Chicago')),
        );

        $this->assertSame('Mon, 01 Mar 2027 15:30:00 GMT', $policy->deprecationHeaders()['Sunset']);
        $this->assertSame('@1793491200', $policy->deprecationHeaders()['Deprecation']);
    }

    #[Test]
    public function date_strings_without_a_time_zone_are_read_as_utc_whatever_the_default_timezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/Chicago');

        try {
            $policy = VersionPolicy::fromArray('ios', [
                'minimum' => '3.0.0',
                'deprecated_at' => '2026-11-01',
                'sunset' => '2027-03-01',
            ]);

            $this->assertSame('@1793491200', $policy->deprecationHeaders()['Deprecation']);
            $this->assertSame('Mon, 01 Mar 2027 00:00:00 GMT', $policy->deprecationHeaders()['Sunset']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    #[Test]
    public function a_sunset_earlier_than_deprecated_at_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('sunset date earlier than its deprecated_at date');

        VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'deprecated_at' => '2027-03-01', 'sunset' => '2026-11-01']);
    }

    #[Test]
    public function a_sunset_equal_to_deprecated_at_is_allowed(): void
    {
        $policy = VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'deprecated_at' => '2027-03-01', 'sunset' => '2027-03-01']);

        $this->assertSame('@1803859200', $policy->deprecationHeaders()['Deprecation']);
        $this->assertSame('Mon, 01 Mar 2027 00:00:00 GMT', $policy->deprecationHeaders()['Sunset']);
    }

    #[Test]
    public function an_unparseable_deprecated_at_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('deprecated_at date "last tuesday-ish"');

        VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'deprecated_at' => 'last tuesday-ish']);
    }

    #[Test]
    public function recommended_defaults_to_minimum_and_latest_to_recommended(): void
    {
        $policy = VersionPolicy::fromArray('ios', ['minimum' => '3.0.0']);

        $this->assertSame('3.0.0', $policy->recommended->toString());
        $this->assertSame('3.0.0', $policy->latest->toString());
        $this->assertSame(UpdateRequirement::None, $policy->evaluate(Version::parse('3.0.0')));
        $this->assertSame(UpdateRequirement::Hard, $policy->evaluate(Version::parse('2.9.0')));

        $policy = VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'recommended' => '3.2.0']);

        $this->assertSame('3.2.0', $policy->latest->toString());
    }

    #[Test]
    public function empty_strings_from_env_read_as_unset(): void
    {
        $policy = VersionPolicy::fromArray('ios', [
            'minimum' => '3.0.0',
            'recommended' => '',
            'latest' => '',
            'store_url' => '',
            'deprecated_at' => '',
            'sunset' => '',
            'message' => '',
        ]);

        $this->assertSame('3.0.0', $policy->latest->toString());
        $this->assertNull($policy->storeUrl);
        $this->assertNull($policy->sunset);
        $this->assertNull($policy->deprecatedAt);
        $this->assertNull($policy->message);
    }

    #[Test]
    public function versions_may_be_given_as_version_instances(): void
    {
        $policy = VersionPolicy::fromArray('ios', ['minimum' => Version::parse('3.0.0'), 'latest' => Version::parse('3.1.0')]);

        $this->assertSame('3.1.0', $policy->latest->toString());
    }

    #[Test]
    public function minimum_is_required(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('no "minimum" version');

        VersionPolicy::fromArray('ios', ['latest' => '3.0.0']);
    }

    #[Test]
    public function a_malformed_version_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('invalid "recommended" version "soon"');

        VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'recommended' => 'soon']);
    }

    #[Test]
    public function a_non_scalar_version_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);

        VersionPolicy::fromArray('ios', ['minimum' => ['3', '0', '0']]);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function outOfOrder(): iterable
    {
        yield 'minimum above recommended' => [['minimum' => '3.5.0', 'recommended' => '3.4.0', 'latest' => '3.6.0']];
        yield 'recommended above latest' => [['minimum' => '3.0.0', 'recommended' => '3.5.0', 'latest' => '3.4.0']];
        yield 'minimum above latest with recommended defaulted' => [['minimum' => '3.5.0', 'latest' => '3.4.0']];
    }

    #[Test]
    #[DataProvider('outOfOrder')]
    public function versions_out_of_order_are_rejected(array $data): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('out of order');

        VersionPolicy::fromArray('ios', $data);
    }

    #[Test]
    public function an_unparseable_sunset_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('sunset date "next spring"');

        VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'sunset' => 'next spring']);
    }

    #[Test]
    public function a_relative_store_url_is_rejected(): void
    {
        $this->expectException(InvalidPolicy::class);
        $this->expectExceptionMessage('store_url');

        VersionPolicy::fromArray('ios', ['minimum' => '3.0.0', 'store_url' => 'apps.apple.com/app/id1']);
    }
}
