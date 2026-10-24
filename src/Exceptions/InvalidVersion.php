<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion\Exceptions;

use InvalidArgumentException;

final class InvalidVersion extends InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'Invalid app version "%s". Expected one to three numeric components such as 3.4.1, optionally followed by a pre-release suffix.',
            $value,
        ));
    }
}
