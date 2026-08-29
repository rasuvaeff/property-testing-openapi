<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Compiles the string, integer, and number schema sections.
 *
 * @internal
 */
final readonly class ScalarArbitraries
{
    private const int MAX_STRING_LENGTH = 64;

    public function __construct(
        private SchemaFacts $facts,
    ) {}

    /** @param array<string, mixed> $schema */
    public function string(array $schema): ArbitraryInterface
    {
        $min = $this->facts->nonNegativeInt($schema, 'minLength', 0);
        $max = $this->facts->nonNegativeInt($schema, 'maxLength', self::MAX_STRING_LENGTH);
        $max = min($max, self::MAX_STRING_LENGTH);
        if ($max === 0) {
            if ($min !== 0) {
                throw UnsupportedGeneration::forSchema('minLength exceeds maxLength or the generation budget');
            }

            return Gen::constant('');
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('minLength exceeds maxLength or the generation budget');
        }

        $format = $schema['format'] ?? null;
        if ($format !== null && !is_string($format)) {
            throw UnsupportedGeneration::forSchema('format must be a string');
        }
        /** @var ArbitraryInterface<string> $arbitrary */
        $arbitrary = match ($format) {
            // `password` is a UI-obscuring annotation in OAS, not an assertion.
            null, 'password' => Gen::stringOf($min, $max),
            'uuid' => Gen::uuid(),
            'email' => Gen::email(),
            'ipv4' => Gen::ipv4(),
            'uri', 'uri-reference', 'url' => Gen::url(),
            'date-time' => Gen::map(Gen::datetime(), static function (mixed $value): string {
                if (!$value instanceof DateTimeImmutable) {
                    throw new \LogicException('Datetime arbitrary produced an invalid value');
                }

                return $value->format(DATE_RFC3339_EXTENDED);
            }),
            'date' => Gen::map(Gen::datetime(), static function (mixed $value): string {
                if (!$value instanceof DateTimeImmutable) {
                    throw new \LogicException('Datetime arbitrary produced an invalid value');
                }

                return $value->format('Y-m-d');
            }),
            default => throw UnsupportedGeneration::forSchema(sprintf('format "%s" is outside the supported format subset', $format)),
        };
        $pattern = $schema['pattern'] ?? null;
        if ($pattern !== null) {
            if (!is_string($pattern)) {
                throw UnsupportedGeneration::forSchema('pattern must be a string');
            }

            try {
                $arbitrary = Gen::stringMatching($pattern);
            } catch (\InvalidArgumentException $exception) {
                throw UnsupportedGeneration::forSchema(sprintf('pattern is not supported: %s', $exception->getMessage()));
            }
        }

        if ($format !== null || $pattern !== null) {
            return Gen::filter($arbitrary, static fn(mixed $value): bool => is_string($value)
                && mb_strlen($value) >= $min
                && mb_strlen($value) <= $max);
        }

        return $arbitrary;
    }

    /** @param array<string, mixed> $schema */
    public function integer(array $schema): ArbitraryInterface
    {
        $min = $this->facts->integerBound($schema, 'minimum', -1000);
        $max = $this->facts->integerBound($schema, 'maximum', 1000);
        if (($schema['exclusiveMinimum'] ?? false) === true) {
            ++$min;
        }
        if (($schema['exclusiveMaximum'] ?? false) === true) {
            --$max;
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('integer bounds leave no value');
        }

        $multiple = $schema['multipleOf'] ?? null;
        if ($multiple === null) {
            return Gen::intBetween($min, $max);
        }
        if (!is_int($multiple) || $multiple <= 0) {
            throw UnsupportedGeneration::forSchema('integer multipleOf must be a positive integer');
        }
        $multipleValue = (float) $multiple;
        $first = (int) ceil((float) $min / $multipleValue);
        $last = (int) floor((float) $max / $multipleValue);
        if ($first > $last) {
            throw UnsupportedGeneration::forSchema('integer multipleOf leaves no value');
        }

        return Gen::map(Gen::intBetween($first, $last), static fn(mixed $value): int => (int) $value * $multiple);
    }

    /** @param array<string, mixed> $schema */
    public function number(array $schema): ArbitraryInterface
    {
        $min = $this->facts->numberBound($schema, 'minimum', -1000.0);
        $max = $this->facts->numberBound($schema, 'maximum', 1000.0);
        if (($schema['exclusiveMinimum'] ?? false) === true) {
            $min += 0.1;
        }
        if (($schema['exclusiveMaximum'] ?? false) === true) {
            $max -= 0.1;
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('number bounds leave no value');
        }

        $multiple = $schema['multipleOf'] ?? null;
        if ($multiple === null) {
            return Gen::floatBetween($min, $max);
        }
        if (!is_int($multiple) && !is_float($multiple)) {
            throw UnsupportedGeneration::forSchema('number multipleOf must be numeric');
        }
        if ($multiple <= 0 || !is_finite((float) $multiple)) {
            throw UnsupportedGeneration::forSchema('number multipleOf must be positive and finite');
        }
        $first = (int) ceil($min / (float) $multiple);
        $last = (int) floor($max / (float) $multiple);
        if ($first > $last) {
            throw UnsupportedGeneration::forSchema('number multipleOf leaves no value');
        }

        return Gen::map(Gen::intBetween($first, $last), static fn(mixed $value): float => (float) $value * (float) $multiple);
    }
}
