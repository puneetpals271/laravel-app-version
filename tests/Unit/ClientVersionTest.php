<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Puneetpal\AppVersion\ClientVersion;
use Puneetpal\AppVersion\Exceptions\InvalidClientHeaders;

final class ClientVersionTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers): Request
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/api/orders', 'GET', [], [], [], $server);
    }

    #[Test]
    public function it_parses_all_three_headers(): void
    {
        $client = ClientVersion::fromRequest($this->request([
            'X-App-Version' => '3.4.1',
            'X-App-Build' => '120',
            'X-App-Platform' => 'ios',
        ]));

        $this->assertNotNull($client);
        $this->assertSame('3.4.1', $client->version->toString());
        $this->assertSame(120, $client->build);
        $this->assertSame('ios', $client->platform);
        $this->assertTrue($client->hasPlatform());
        $this->assertSame(['version' => '3.4.1', 'build' => 120, 'platform' => 'ios'], $client->toArray());
    }

    #[Test]
    public function it_returns_null_when_no_version_header_is_sent(): void
    {
        $this->assertNull(ClientVersion::fromRequest($this->request([])));
        $this->assertNull(ClientVersion::fromRequest($this->request(['X-App-Platform' => 'ios', 'X-App-Build' => '120'])));
        $this->assertNull(ClientVersion::fromRequest($this->request(['X-App-Version' => '   '])));
    }

    #[Test]
    public function build_and_platform_are_optional(): void
    {
        $client = ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4']));

        $this->assertNotNull($client);
        $this->assertSame('3.4.0', $client->version->toString());
        $this->assertNull($client->build);
        $this->assertNull($client->platform);
        $this->assertFalse($client->hasPlatform());
    }

    #[Test]
    public function platform_is_normalised_to_lower_case(): void
    {
        $client = ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Platform' => ' iOS ']));

        $this->assertSame('ios', $client?->platform);
    }

    #[Test]
    public function an_empty_platform_header_reads_as_absent(): void
    {
        $client = ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Platform' => '']));

        $this->assertNull($client?->platform);
    }

    #[Test]
    public function an_empty_build_header_reads_as_absent(): void
    {
        $client = ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Build' => '']));

        $this->assertNull($client?->build);
    }

    #[Test]
    public function a_malformed_version_throws(): void
    {
        try {
            ClientVersion::fromRequest($this->request(['X-App-Version' => 'latest']));
            $this->fail('Expected InvalidClientHeaders.');
        } catch (InvalidClientHeaders $e) {
            $this->assertSame('X-App-Version', $e->header);
            $this->assertSame('invalid_app_version', $e->errorCode);
            $this->assertStringContainsString('latest', $e->getMessage());
        }
    }

    #[Test]
    public function a_malformed_build_throws(): void
    {
        foreach (['abc', '1.2', '-5', '12345678901234567890'] as $build) {
            try {
                ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Build' => $build]));
                $this->fail('Expected InvalidClientHeaders for build '.$build);
            } catch (InvalidClientHeaders $e) {
                $this->assertSame('X-App-Build', $e->header);
                $this->assertSame('invalid_app_build', $e->errorCode);
            }
        }
    }

    #[Test]
    public function a_malformed_platform_throws(): void
    {
        foreach (['i/os', 'ios.minimum', "ios\xff", '-ios', str_repeat('a', 33)] as $platform) {
            try {
                ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Platform' => $platform]));
                $this->fail('Expected InvalidClientHeaders for platform '.bin2hex($platform));
            } catch (InvalidClientHeaders $e) {
                $this->assertSame('X-App-Platform', $e->header);
                $this->assertSame('invalid_app_platform', $e->errorCode);
            }
        }
    }

    #[Test]
    public function platform_names_with_digits_hyphens_and_underscores_are_accepted(): void
    {
        $client = ClientVersion::fromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Platform' => 'Android-TV_2']));

        $this->assertSame('android-tv_2', $client?->platform);
    }

    #[Test]
    public function the_quoted_value_in_an_error_message_is_printable_ascii_of_bounded_length(): void
    {
        try {
            ClientVersion::fromRequest($this->request(['X-App-Version' => "3.\xff.1\n".str_repeat('x', 200)]));
            $this->fail('Expected InvalidClientHeaders.');
        } catch (InvalidClientHeaders $e) {
            $message = $e->getMessage();

            $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $message), 'message must be printable ASCII');
            $this->assertStringContainsString('"3.?.1?xxx', $message);
            $this->assertStringContainsString('..."', $message);
            $this->assertLessThan(200, strlen($message));
            $this->assertNotFalse(json_encode($message));
        }
    }

    #[Test]
    public function try_from_request_swallows_malformed_headers(): void
    {
        $this->assertNull(ClientVersion::tryFromRequest($this->request(['X-App-Version' => 'latest'])));
        $this->assertNull(ClientVersion::tryFromRequest($this->request(['X-App-Version' => '3.4.1', 'X-App-Platform' => 'i/os'])));
        $this->assertNotNull(ClientVersion::tryFromRequest($this->request(['X-App-Version' => '3.4.1'])));
    }
}
