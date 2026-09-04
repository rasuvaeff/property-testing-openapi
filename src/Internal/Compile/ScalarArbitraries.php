<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use DateTimeImmutable;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Compiles the string, integer, and number schema sections.
 *
 * @internal
 */
final readonly class ScalarArbitraries
{
    private const int MAX_STRING_LENGTH = 64;

    /** Twice the retry budget of `Gen::filter()`, so the probe dominates it. */
    private const int PATTERN_PROBES = 200;

    private const int PATTERN_PROBE_SEED = 7;

    /**
     * The length band each format generator can produce; a length window
     * outside it cannot be satisfied and fails closed at compile time.
     *
     * @var array<string, array{int, int}>
     */
    private const array FORMAT_LENGTHS = [
        'uuid' => [36, 36],
        'email' => [6, 36],
        'ipv4' => [7, 15],
        'uri' => [12, 55],
        'uri-reference' => [12, 55],
        'url' => [12, 55],
        'date-time' => [29, 29],
        'date' => [10, 10],
    ];

    /**
     * Printable ASCII, the domain {@see Gen::stringOf()} draws from. Spelled
     * out so a character can be taken out of it: a delimited query parameter
     * cannot carry its own separator, and narrowing the alphabet is the
     * constructive way to say so — the alternative is a filter that rejects
     * four generated strings in five.
     */
    private const string PRINTABLE_ASCII = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    public function __construct(
        private SchemaFacts $facts,
        private string $excludedCharacters = '',
    ) {}

    /**
     * Decimal places the multiple carries, so a product can be rounded back to
     * a value a strict server recognises. Beyond `PHP_FLOAT_DIG` the digits are
     * noise rather than precision.
     */
    private function decimals(float $multiple): int
    {
        $rendered = rtrim(sprintf('%.*F', PHP_FLOAT_DIG, $multiple), '0');

        return strlen(explode('.', $rendered . '.')[1]);
    }

    /**
     * Whether a pattern can produce a string inside the length window. The
     * probe is deterministic and twice the retry budget `Gen::filter()` would
     * have had, so a window it cannot hit is one the filter would exhaust —
     * reported here, naming both constraints, instead of as a run-time
     * `GenerationExhausted` the package's own rules call a defect.
     *
     * @param ArbitraryInterface<string> $arbitrary
     */
    private function fitsLengthWindow(ArbitraryInterface $arbitrary, int $min, int $max): bool
    {
        $random = new Random(self::PATTERN_PROBE_SEED);
        for ($probe = 0; $probe < self::PATTERN_PROBES; ++$probe) {
            /** @var mixed $value */
            $value = $arbitrary->generate($random)->value;
            if (is_string($value) && mb_strlen($value) >= $min && mb_strlen($value) <= $max) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schema */
    public function string(array $schema): ArbitraryInterface
    {
        $min = $this->facts->nonNegativeInt($schema, 'minLength', 0);
        $max = $this->facts->nonNegativeInt($schema, 'maxLength', self::MAX_STRING_LENGTH);
        $max = min($max, self::MAX_STRING_LENGTH);
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('minLength exceeds maxLength or the generation budget');
        }
        $format = $schema['format'] ?? null;
        if ($format !== null && !is_string($format)) {
            throw UnsupportedGeneration::forSchema('format must be a string');
        }
        $pattern = $schema['pattern'] ?? null;
        if ($pattern !== null && !is_string($pattern)) {
            throw UnsupportedGeneration::forSchema('pattern must be a string');
        }
        if ($pattern !== null && $format !== null && $format !== 'password') {
            throw UnsupportedGeneration::forSchema(sprintf('pattern combined with format "%s" is outside the supported subset', $format));
        }
        $band = $format === null ? null : (self::FORMAT_LENGTHS[$format] ?? null);
        if ($format !== null && $band !== null && ($band[0] > $max || $band[1] < $min)) {
            throw UnsupportedGeneration::forSchema(sprintf('format "%s" cannot satisfy the length window', $format));
        }
        if ($max === 0) {
            return Gen::constant('');
        }
        /** @var ArbitraryInterface<string> $arbitrary */
        $arbitrary = match ($format) {
            // `password` is a UI-obscuring annotation in OAS, not an assertion.
            null, 'password' => $this->plainString($min, $max),
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
        if ($pattern !== null) {
            try {
                $arbitrary = Gen::stringMatching($pattern);
            } catch (\InvalidArgumentException $exception) {
                throw UnsupportedGeneration::forSchema(sprintf('pattern is not supported: %s', $exception->getMessage()));
            }
        }

        if ($pattern !== null && !$this->fitsLengthWindow($arbitrary, $min, $max)) {
            throw UnsupportedGeneration::forSchema(sprintf('pattern cannot satisfy the length window [%d, %d]', $min, $max));
        }
        if ($format !== null || $pattern !== null) {
            return Gen::filter($arbitrary, static fn(mixed $value): bool => is_string($value)
                && mb_strlen($value) >= $min
                && mb_strlen($value) <= $max);
        }

        return $arbitrary;
    }

    /**
     * @param int<0, max> $min
     * @param int<1, max> $max the zero-length window is answered before this
     * @return ArbitraryInterface<string>
     */
    private function plainString(int $min, int $max): ArbitraryInterface
    {
        if ($this->excludedCharacters === '') {
            return Gen::stringOf($min, $max);
        }
        $alphabet = str_replace(str_split($this->excludedCharacters), '', self::PRINTABLE_ASCII);
        if ($alphabet === '') {
            throw UnsupportedGeneration::forSchema('the excluded characters leave no alphabet');
        }

        /** @var ArbitraryInterface<string> $arbitrary */
        $arbitrary = Gen::stringFrom($alphabet, minLength: $min, maxLength: $max);

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
        $exclusiveMinimum = ($schema['exclusiveMinimum'] ?? false) === true;
        $exclusiveMaximum = ($schema['exclusiveMaximum'] ?? false) === true;
        if (($exclusiveMinimum || $exclusiveMaximum) && $min >= $max) {
            throw UnsupportedGeneration::forSchema('number bounds leave no value');
        }
        // Step inside an open bound by a tenth, or by a quarter of a narrow
        // window, so that `(0, 0.05]` still leaves values.
        $step = min(0.1, ($max - $min) / 4.0);
        if ($exclusiveMinimum) {
            $min += $step;
        }
        if ($exclusiveMaximum) {
            $max -= $step;
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

        // `3 * 0.1` is `0.30000000000000004`. Our own oracle tolerates that,
        // but a server checking `fmod` without a tolerance does not, and the
        // failure would be reported against the user's API. Round back to the
        // precision the multiple itself carries.
        $decimals = $this->decimals((float) $multiple);

        return Gen::map(
            Gen::intBetween($first, $last),
            static fn(mixed $value): float => round((float) $value * (float) $multiple, $decimals),
        );
    }
}
