<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Puneetpal\AppVersion\Exceptions\InvalidVersion;
use Puneetpal\AppVersion\Version;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validVersions(): iterable
    {
        yield 'three components' => ['3.4.1', '3.4.1'];
        yield 'two components fill patch with zero' => ['3.4', '3.4.0'];
        yield 'one component fills minor and patch' => ['3', '3.0.0'];
        yield 'leading v is accepted' => ['v3.4.1', '3.4.1'];
        yield 'surrounding whitespace is trimmed' => ['  3.4.1 ', '3.4.1'];
        yield 'pre-release suffix' => ['3.4.1-beta.2', '3.4.1-beta.2'];
        yield 'build metadata' => ['3.4.1+77', '3.4.1+77'];
        yield 'pre-release and build metadata' => ['3.4.1-rc.1+77', '3.4.1-rc.1+77'];
        yield 'zero version' => ['0.0.0', '0.0.0'];
        yield 'large components' => ['2026.10.24', '2026.10.24'];
    }

    #[Test]
    #[DataProvider('validVersions')]
    public function it_parses_and_normalises_valid_versions(string $input, string $normalised): void
    {
        $version = Version::parse($input);

        $this->assertSame($normalised, $version->toString());
        $this->assertSame($normalised, (string) $version);
        $this->assertSame($normalised, json_decode(json_encode($version)));
        $this->assertTrue(Version::isValid($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'word' => ['latest'];
        yield 'four components' => ['3.4.1.2'];
        yield 'empty component' => ['3..1'];
        yield 'trailing dot' => ['3.4.'];
        yield 'leading zero' => ['03.4.1'];
        yield 'negative' => ['-3.4.1'];
        yield 'letters in core' => ['3.4a.1'];
        yield 'empty pre-release' => ['3.4.1-'];
        yield 'empty build metadata' => ['3.4.1+'];
        yield 'space inside' => ['3.4 .1'];
        yield 'injection attempt' => ['3.4.1; DROP TABLE users'];
    }

    #[Test]
    #[DataProvider('invalidVersions')]
    public function it_rejects_invalid_versions(string $input): void
    {
        $this->assertFalse(Version::isValid($input));
        $this->assertNull(Version::tryParse($input));

        $this->expectException(InvalidVersion::class);
        Version::parse($input);
    }

    #[Test]
    public function try_parse_returns_null_for_null(): void
    {
        $this->assertNull(Version::tryParse(null));
    }

    #[Test]
    public function it_exposes_components(): void
    {
        $version = Version::parse('3.4.1-beta.2+77');

        $this->assertSame(3, $version->major);
        $this->assertSame(4, $version->minor);
        $this->assertSame(1, $version->patch);
        $this->assertSame(['beta', '2'], $version->preRelease);
        $this->assertSame('77', $version->buildMetadata);
        $this->assertTrue($version->isPreRelease());
        $this->assertFalse(Version::parse('3.4.1')->isPreRelease());
    }

    /**
     * Each entry is strictly lower than the next, following semver precedence.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function orderedPairs(): iterable
    {
        yield 'patch' => ['3.4.0', '3.4.1'];
        yield 'minor' => ['3.4.9', '3.5.0'];
        yield 'major' => ['3.99.99', '4.0.0'];
        yield 'numeric not lexical' => ['3.4.9', '3.4.10'];
        yield 'two versus three components' => ['3.4', '3.4.1'];
        yield 'pre-release below release' => ['3.4.1-beta.1', '3.4.1'];
        yield 'pre-release numeric identifiers compare numerically' => ['3.4.1-beta.2', '3.4.1-beta.10'];
        yield 'numeric identifier below alphanumeric' => ['3.4.1-1', '3.4.1-alpha'];
        yield 'alphanumeric compares lexically' => ['3.4.1-alpha', '3.4.1-beta'];
        yield 'shorter pre-release below longer with same prefix' => ['3.4.1-alpha', '3.4.1-alpha.1'];
        yield 'pre-release of next patch above release' => ['3.4.1', '3.4.2-alpha'];
    }

    #[Test]
    #[DataProvider('orderedPairs')]
    public function it_compares_by_semver_precedence(string $lower, string $higher): void
    {
        $a = Version::parse($lower);
        $b = Version::parse($higher);

        $this->assertSame(-1, $a->compare($b));
        $this->assertSame(1, $b->compare($a));
        $this->assertTrue($a->isLowerThan($b));
        $this->assertFalse($b->isLowerThan($a));
        $this->assertTrue($b->isAtLeast($a));
        $this->assertFalse($a->isAtLeast($b));
        $this->assertFalse($a->equals($b));
    }

    #[Test]
    public function equal_versions_compare_as_equal(): void
    {
        $this->assertSame(0, Version::parse('3.4')->compare(Version::parse('3.4.0')));
        $this->assertTrue(Version::parse('v3.4.0')->equals(Version::parse('3.4.0')));
        $this->assertTrue(Version::parse('3.4.0')->isAtLeast(Version::parse('3.4.0')));
        $this->assertFalse(Version::parse('3.4.0')->isLowerThan(Version::parse('3.4.0')));
    }

    #[Test]
    public function build_metadata_is_ignored_for_precedence(): void
    {
        $this->assertTrue(Version::parse('3.4.1+100')->equals(Version::parse('3.4.1+1')));
        $this->assertTrue(Version::parse('3.4.1+100')->equals(Version::parse('3.4.1')));
    }
}
