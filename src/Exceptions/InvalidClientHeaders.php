<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Exceptions;

use Puneetpal\AppVersion\Headers;
use RuntimeException;

/**
 * Thrown when a request carries an X-App-* header whose value cannot be read.
 * The middleware turns it into a 400 response with the error code.
 *
 * The offending value is quoted in the message so the app developer can see
 * what was sent, but only after it has been reduced to a short run of
 * printable ASCII: header values are attacker-controlled and the message ends
 * up in a JSON body and possibly a log line.
 */
final class InvalidClientHeaders extends RuntimeException
{
    private const QUOTED_VALUE_LIMIT = 64;

    private function __construct(
        public readonly string $header,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function version(string $value): self
    {
        return new self(
            Headers::VERSION,
            'invalid_app_version',
            sprintf('The %s header "%s" is not a valid version. Send a semantic version such as 3.4.1.', Headers::VERSION, self::quote($value)),
        );
    }

    public static function build(string $value): self
    {
        return new self(
            Headers::BUILD,
            'invalid_app_build',
            sprintf('The %s header "%s" is not a valid build number. Send a non-negative integer.', Headers::BUILD, self::quote($value)),
        );
    }

    public static function platform(string $value): self
    {
        return new self(
            Headers::PLATFORM,
            'invalid_app_platform',
            sprintf('The %s header "%s" is not a valid platform name. Send a short lower-case name such as ios or android.', Headers::PLATFORM, self::quote($value)),
        );
    }

    /**
     * Reduces a client-supplied value to printable ASCII of bounded length so
     * it is always safe to JSON-encode and cannot carry control characters.
     */
    public static function quote(string $value): string
    {
        $printable = (string) preg_replace('/[^\x20-\x7E]/', '?', trim($value));

        if (strlen($printable) <= self::QUOTED_VALUE_LIMIT) {
            return $printable;
        }

        return substr($printable, 0, self::QUOTED_VALUE_LIMIT).'...';
    }
}
