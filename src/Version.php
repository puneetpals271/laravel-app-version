<?php

declare(strict_types=1);

namespace Puneetpal\AppVersion;

use JsonSerializable;
use Puneetpal\AppVersion\Exceptions\InvalidVersion;
use Stringable;

/**
 * A semantic version as mobile apps report it: one to three numeric components
 * (missing components read as 0), an optional pre-release suffix and optional
 * build metadata. "3.4" and "3.4.0" compare equal; "3.4.1-beta.1" sorts below
 * "3.4.1"; build metadata is ignored for comparison, as semver requires.
 */
final class Version implements JsonSerializable, Stringable
{
    private const PATTERN = '/^v?(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?(?:\.(0|[1-9]\d*))?(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';

    /**
     * @param  list<string>  $preRelease
     */
    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
        public readonly array $preRelease = [],
        public readonly ?string $buildMetadata = null,
    ) {
    }

    /**
     * @throws InvalidVersion
     */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '' || preg_match(self::PATTERN, $trimmed, $m) !== 1) {
            throw InvalidVersion::forValue($value);
        }

        return new self(
            (int) $m[1],
            (int) ($m[2] ?? 0),
            (int) ($m[3] ?? 0),
            isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [],
            isset($m[5]) && $m[5] !== '' ? $m[5] : null,
        );
    }

    public static function tryParse(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::parse($value);
        } catch (InvalidVersion) {
            return null;
        }
    }

    public static function isValid(string $value): bool
    {
        return self::tryParse($value) !== null;
    }

    public function isPreRelease(): bool
    {
        return $this->preRelease !== [];
    }

    /**
     * Semver precedence: -1 when this is lower, 0 when equal, 1 when higher.
     */
    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];

        if ($core !== 0) {
            return $core;
        }

        if ($this->preRelease === [] || $other->preRelease === []) {
            // A pre-release has lower precedence than the associated normal version.
            return ($this->preRelease === [] ? 1 : 0) <=> ($other->preRelease === [] ? 1 : 0);
        }

        $count = min(count($this->preRelease), count($other->preRelease));

        for ($i = 0; $i < $count; $i++) {
            $a = $this->preRelease[$i];
            $b = $other->preRelease[$i];

            if ($a === $b) {
                continue;
            }

            $aNumeric = ctype_digit($a);
            $bNumeric = ctype_digit($b);

            if ($aNumeric && $bNumeric) {
                return (int) $a <=> (int) $b;
            }

            if ($aNumeric !== $bNumeric) {
                // Numeric identifiers always have lower precedence than alphanumeric ones.
                return $aNumeric ? -1 : 1;
            }

            return strcmp($a, $b) < 0 ? -1 : 1;
        }

        return count($this->preRelease) <=> count($other->preRelease);
    }

    public function isLowerThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    /**
     * The normalised form, always with three numeric components.
     */
    public function toString(): string
    {
        $value = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        if ($this->preRelease !== []) {
            $value .= '-'.implode('.', $this->preRelease);
        }

        if ($this->buildMetadata !== null) {
            $value .= '+'.$this->buildMetadata;
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
