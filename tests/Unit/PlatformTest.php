<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Puneetpal\AppVersion\Platform;

final class PlatformTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function names(): iterable
    {
        yield 'lower case' => ['ios', 'ios'];
        yield 'mixed case with padding' => [' iOS ', 'ios'];
        yield 'digits, hyphen and underscore' => ['Android-TV_2', 'android-tv_2'];
        yield 'single character' => ['x', 'x'];
        yield 'longest allowed' => [str_repeat('a', 32), str_repeat('a', 32)];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'too long' => [str_repeat('a', 33), null];
        yield 'leading hyphen' => ['-ios', null];
        yield 'dot (config path separator)' => ['ios.minimum', null];
        yield 'slash' => ['i/os', null];
        yield 'space inside' => ['i os', null];
        yield 'control character' => ["ios\x7f", null];
        yield 'invalid utf-8' => ["ios\xff", null];
        yield 'unicode letters' => ['ïos', null];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_normalises_or_rejects_each_name(?string $value, ?string $expected): void
    {
        $this->assertSame($expected, Platform::tryNormalise($value));

        if ($value !== null) {
            $this->assertSame($expected !== null, Platform::isValid($value));
        }
    }
}
