<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\SchemaFacts;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ScalarArbitraries::class)]
#[Covers(SchemaFacts::class)]
final class ScalarArbitrariesTest
{
    /**
     * The OAS 3.1 spelling of an exclusive bound is a number (#166): every
     * value drawn is one the contract accepts, the values next to the bound
     * are reached, and the bound itself never is.
     *
     * @param list<int|float> $reached
     * @param list<int|float> $never
     */
    #[DataProvider('numericExclusiveProvider')]
    public function aNumericExclusiveBoundIsHonouredAndReached(array $schema, array $reached, array $never): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 300, seed: 5);
        foreach ($values as $value) {
            Assert::true((new SchemaCheck())->accepts($value, $schema, SchemaDialect::OpenApi31), json_encode([$schema, $value], JSON_THROW_ON_ERROR));
        }
        foreach ($reached as $value) {
            Assert::true(in_array($value, $values, strict: true), json_encode($value, JSON_THROW_ON_ERROR));
        }
        foreach ($never as $value) {
            Assert::false(in_array($value, $values, strict: true), json_encode($value, JSON_THROW_ON_ERROR));
        }
    }

    public static function numericExclusiveProvider(): iterable
    {
        yield 'integer above zero' => [['type' => 'integer', 'exclusiveMinimum' => 0, 'maximum' => 3], [1, 2, 3], [0]];
        yield 'integer below three' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMaximum' => 3], [0, 1, 2], [3]];
        yield 'integer between both' => [['type' => 'integer', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 3], [1, 2], [0, 3]];
        yield 'integer above a fraction' => [['type' => 'integer', 'exclusiveMinimum' => 0.5, 'maximum' => 2], [1, 2], [0]];
        yield 'integer below a fraction' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMaximum' => 2.5], [0, 1, 2], [3]];
        yield 'integer above an integral float' => [['type' => 'integer', 'exclusiveMinimum' => 1.0, 'maximum' => 3], [2, 3], [1]];
        yield 'a tighter inclusive minimum wins' => [['type' => 'integer', 'minimum' => 2, 'exclusiveMinimum' => 0, 'maximum' => 3], [2, 3], [1]];
        yield 'a tighter exclusive minimum wins' => [['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => 1, 'maximum' => 3], [2, 3], [1]];
        yield 'a tighter inclusive maximum wins' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 1, 'exclusiveMaximum' => 3], [0, 1], [2]];
        yield 'a tighter exclusive maximum wins' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 3, 'exclusiveMaximum' => 2], [0, 1], [2]];
        yield 'an equal bound is exclusive' => [['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => 1, 'maximum' => 3, 'exclusiveMaximum' => 3], [2], [1, 3]];
        yield 'number between both' => [['type' => 'number', 'exclusiveMinimum' => 0, 'exclusiveMaximum' => 1], [], [0.0, 1.0]];
        yield 'number with a multiple' => [['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 0.3, 'multipleOf' => 0.1], [0.1, 0.2], [0.0]];
        yield 'number with a tighter inclusive maximum' => [['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'exclusiveMaximum' => 5, 'multipleOf' => 1], [0.0, 1.0], [2.0]];
    }

    public function aSchemaWithoutANumericExclusiveBoundIsUntouched(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true, 'maximum' => 5, 'exclusiveMaximum' => false];

        Assert::same(SchemaFacts::exclusiveBounds($schema, integer: true), $schema);
        Assert::same(SchemaFacts::exclusiveBounds(['type' => 'number', 'exclusiveMinimum' => 0.5], integer: false), ['type' => 'number', 'minimum' => 0.5, 'exclusiveMinimum' => true]);
        Assert::same(SchemaFacts::exclusiveBounds(['type' => 'integer', 'exclusiveMinimum' => 0.5], integer: true), ['type' => 'integer', 'minimum' => 0.5]);
        Assert::same(SchemaFacts::exclusiveBounds(['type' => 'integer', 'maximum' => 1, 'exclusiveMaximum' => 3], integer: true), ['type' => 'integer', 'maximum' => 1]);
    }

    /**
     * The 64-character budget yields to a declared `minLength` above it
     * (#169); under it the cap is what it was.
     */
    #[DataProvider('stringBudgetProvider')]
    public function theStringBudgetYieldsToADeclaredMinimum(array $schema, int $shortest, int $longest): void
    {
        $lengths = array_map(static fn(mixed $value): int => is_string($value) ? mb_strlen($value) : -1, Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 200, seed: 4));

        Assert::same([min($lengths), max($lengths)], [$shortest, $longest]);
    }

    public static function stringBudgetProvider(): iterable
    {
        yield 'minLength under the budget' => [['type' => 'string', 'minLength' => 63], 63, 64];
        yield 'minLength at the budget' => [['type' => 'string', 'minLength' => 64], 64, 64];
        yield 'minLength past the budget' => [['type' => 'string', 'minLength' => 65], 65, 65];
        yield 'minLength past the budget with room declared' => [['type' => 'string', 'minLength' => 100, 'maxLength' => 120], 100, 100];
        yield 'a maximum past the budget is still capped' => [['type' => 'string', 'minLength' => 60, 'maxLength' => 120], 60, 64];
    }

    /**
     * The four formats the contract validates and the generator used to
     * refuse (#168): every value drawn is one the contract accepts, and each
     * format is drawn often enough for that to mean something.
     *
     * @param array{format: string, value: mixed} $drawn
     */
    #[Property(runs: 400)]
    public function everyValueOfANewFormatIsOneTheContractAccepts(array $drawn): void
    {
        $schema = ['type' => 'string', 'format' => $drawn['format']];

        Classify::cover($drawn['format'] === 'hostname', 'hostname', 15.0);
        Classify::cover($drawn['format'] === 'ipv6', 'ipv6', 15.0);
        Classify::cover($drawn['format'] === 'time', 'time', 15.0);
        Classify::cover($drawn['format'] === 'duration', 'duration', 15.0);
        Assert::true(is_string($drawn['value']));
        Assert::true((new SchemaCheck())->accepts($drawn['value'], $schema, SchemaDialect::OpenApi31), json_encode($drawn, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyValueOfANewFormatIsOneTheContractAcceptsGenerators(): array
    {
        return ['drawn' => Gen::flatMap(
            Gen::elements(['hostname', 'ipv6', 'time', 'duration']),
            static fn(mixed $format): ArbitraryInterface => Gen::map(
                (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => $format]),
                static fn(mixed $value): array => ['format' => $format, 'value' => $value],
            ),
        )];
    }

    /**
     * The length band each new format is generated in: a window inside it
     * keeps the format, one outside it is refused at compile time.
     */
    #[DataProvider('newFormatBandProvider')]
    public function aNewFormatStaysInsideItsLengthBand(string $format, int $shortest, int $longest): void
    {
        $lengths = array_map(static fn(mixed $value): int => is_string($value) ? strlen($value) : -1, Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => $format]), count: 300, seed: 6));

        Assert::true(min($lengths) >= $shortest && max($lengths) <= $longest, json_encode([min($lengths), max($lengths)], JSON_THROW_ON_ERROR));
    }

    public static function newFormatBandProvider(): iterable
    {
        yield 'hostname' => ['hostname', 1, 32];
        yield 'ipv6' => ['ipv6', 15, 39];
        yield 'time' => ['time', 9, 14];
        yield 'duration' => ['duration', 8, 11];
    }

    #[DataProvider('newFormatBandProvider')]
    public function aNewFormatOutsideItsLengthBandIsRefused(string $format, int $shortest, int $longest): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage(sprintf('Unsupported OpenAPI schema generation: format "%s" cannot satisfy the length window', $format));

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => $format, 'minLength' => $longest + 1]);
    }

    #[DataProvider('newFormatBandProvider')]
    public function aNewFormatBelowItsLengthBandIsRefused(string $format, int $shortest): void
    {
        if ($shortest === 1) {
            // A hostname is never shorter than one character: no window
            // under its band is a non-empty one.
            Assert::same($shortest, 1);

            return;
        }
        Expect::exception(UnsupportedGeneration::class)->withMessage(sprintf('Unsupported OpenAPI schema generation: format "%s" cannot satisfy the length window', $format));

        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => $format, 'maxLength' => $shortest - 1]);
    }

    #[DataProvider('newFormatBandProvider')]
    public function theWitnessOfANewFormatIsOneTheContractRejects(string $format): void
    {
        Assert::false((new SchemaCheck())->accepts(SchemaProbe::FORMAT_WITNESSES[$format], ['type' => 'string', 'format' => $format], SchemaDialect::OpenApi31));
    }

    public function aFormatStringHonoursItsLengthWindow(): void
    {
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'format' => 'email', 'minLength' => 14, 'maxLength' => 20]), count: 50, seed: 1) as $value) {
            Assert::true(is_string($value) && strlen($value) >= 14 && strlen($value) <= 20, sprintf('"%s"', (string) $value));
        }
    }

    /**
     * `0.1` needs one decimal, so the multiples stay exact while
     * `reach * 10 <= 10^15` — the PHP_FLOAT_DIG digits every double spells
     * back — which is `1e14` for the bound. Past it, at `8.5e14`, a
     * generated multiple landed above `maximum` (850000000000000.1, #145).
     */
    #[DataProvider('decimalReachProvider')]
    public function aDecimalMultipleIsRefusedExactlyPastTheDoublesPrecision(float $maximum, bool $accepted): void
    {
        $schema = ['type' => 'number', 'minimum' => $maximum - 100, 'maximum' => $maximum, 'multipleOf' => 0.1];
        if (!$accepted) {
            Expect::exception(UnsupportedGeneration::class);
        }

        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 200, seed: 1) as $value) {
            Assert::true(is_float($value) && $value <= $maximum && $value >= $maximum - 100 && SchemaCheck::isMultipleOf($value, 0.1), json_encode($value, JSON_THROW_ON_ERROR));
        }
    }

    public static function decimalReachProvider(): iterable
    {
        yield 'well inside' => [5e13, true];
        yield 'exactly at 10^15 after scaling' => [1e14, true];
        yield 'one double past it' => [100000000000000.02, false];
        yield 'below 2^53 after scaling, still past the digits (#145)' => [8.5e14, false];
    }

    /**
     * A bound one bit off a decimal multiple rounds the binary quotient onto
     * the multiple, and its decimal falls outside the bound.
     */
    #[DataProvider('binaryBoundProvider')]
    public function aMultipleNextToABinaryBoundStaysInsideIt(float $minimum, float $maximum, float $multiple, float $nearest): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'number', 'minimum' => $minimum, 'maximum' => $maximum, 'multipleOf' => $multiple]), count: 200, seed: 1);

        foreach ($values as $value) {
            Assert::true(is_float($value) && $value >= $minimum && $value <= $maximum, json_encode($value, JSON_THROW_ON_ERROR));
        }
        Assert::true(in_array($nearest, $values, strict: true), 'the nearest multiple inside the bound is reached');
    }

    #[DataProvider('binaryBoundOnlyMultipleProvider')]
    public function aWindowWhoseOnlyMultipleRoundsOutsideItIsRefused(float $minimum, float $maximum, float $multiple): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation: number multipleOf leaves no value');

        (new SchemaArbitraryCompiler())->compile(['type' => 'number', 'minimum' => $minimum, 'maximum' => $maximum, 'multipleOf' => $multiple]);
    }

    public static function binaryBoundOnlyMultipleProvider(): iterable
    {
        yield 'above the maximum' => [3070.75, 3070.7999999999997, 0.3];
        yield 'below the minimum' => [50849.200000000004, 50849.25, 0.1];
    }

    public static function binaryBoundProvider(): iterable
    {
        yield 'maximum just below 3070.8' => [3069.0, 3070.7999999999997, 0.3, 3070.5];
        yield 'minimum just above 50849.2' => [50849.200000000004, 50850.0, 0.1, 50849.3];
    }

    public function aDecimalReachPastPrecisionNamesTheMultiple(): void
    {
        try {
            (new SchemaArbitraryCompiler())->compile(['type' => 'number', 'minimum' => 0, 'maximum' => 9.5e14, 'multipleOf' => 0.1]);
        } catch (UnsupportedGeneration $exception) {
            Assert::true(str_starts_with($exception->getMessage(), 'Unsupported OpenAPI schema generation: number multipleOf 0.1 cannot be spelled exactly up to '));

            return;
        }

        Assert::true(actual: false, message: 'the schema was compiled');
    }

    /**
     * Lengths are characters, not bytes: `é` is one character in two bytes.
     *
     * @param list<string> $expected
     */
    #[DataProvider('multibyteWindowProvider')]
    public function aPatternLengthWindowCountsCharacters(int $min, int $max, array $expected): void
    {
        $schema = ['type' => 'string', 'pattern' => '^(é|ab)$', 'minLength' => $min, 'maxLength' => $max];

        $values = array_values(array_unique(Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 30, seed: 1)));

        Assert::same($values, $expected);
    }

    public static function multibyteWindowProvider(): iterable
    {
        yield 'two characters' => [2, 2, ['ab']];
        yield 'one character' => [1, 1, ['é']];
    }

    public function aMultibyteCharacterDoesNotFillATwoCharacterWindow(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: pattern cannot satisfy the length window [2, 2]');
        (new SchemaArbitraryCompiler())->compile(['type' => 'string', 'pattern' => '^é$', 'minLength' => 2, 'maxLength' => 2]);
    }

    public function aMultibyteCharacterFitsAOneCharacterWindow(): void
    {
        Assert::same(Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'string', 'pattern' => '^é$', 'minLength' => 1, 'maxLength' => 1]), count: 3, seed: 1), ['é', 'é', 'é']);
    }

    public function aNumberWindowWithoutAMultipleIsRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: number multipleOf leaves no value');
        (new SchemaArbitraryCompiler())->compile(['type' => 'number', 'minimum' => 0.1, 'maximum' => 0.2, 'multipleOf' => 1]);
    }

    /**
     * A bound declared on one side only and lying past the default window
     * takes the other side 2000 from itself (#155); one inside the window
     * keeps the window's end, so what generated before generates the same.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('oneSidedBoundProvider')]
    public function aOneSidedBoundPastTheWindowTakesTheOtherSideFromItself(array $schema, int|float $low, int|float $high): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 200, seed: 3);
        Assert::true(min($values) >= $low, sprintf('min %s below %s', (string) min($values), (string) $low));
        Assert::true(max($values) <= $high, sprintf('max %s above %s', (string) max($values), (string) $high));
        Assert::true(max($values) > $low);
    }

    public static function oneSidedBoundProvider(): iterable
    {
        yield 'integer minimum' => [['type' => 'integer', 'minimum' => 1900], 1900, 3900];
        yield 'integer exclusive minimum at the window' => [['type' => 'integer', 'minimum' => 1000, 'exclusiveMinimum' => true], 1001, 3001];
        yield 'integer maximum' => [['type' => 'integer', 'maximum' => -5000], -7000, -5000];
        yield 'integer exclusive maximum at the window' => [['type' => 'integer', 'maximum' => -1000, 'exclusiveMaximum' => true], -3001, -1001];
        yield 'integer minimum inside the window' => [['type' => 'integer', 'minimum' => 999], 999, 1000];
        yield 'integer maximum inside the window' => [['type' => 'integer', 'maximum' => -999], -1000, -999];
        yield 'number minimum' => [['type' => 'number', 'minimum' => 2000.5], 2000.5, 4000.5];
        yield 'number maximum' => [['type' => 'number', 'maximum' => -2000.5], -4000.5, -2000.5];
        yield 'integer minimum near the native limit' => [['type' => 'integer', 'minimum' => PHP_INT_MAX - 10], PHP_INT_MAX - 10, PHP_INT_MAX];
        yield 'integer maximum near the native limit' => [['type' => 'integer', 'maximum' => PHP_INT_MIN + 10], PHP_INT_MIN, PHP_INT_MIN + 10];
    }

    public function aOneSidedBoundAtTheEdgeOfTheSaturationWindowDrawsTheFullWindow(): void
    {
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'integer', 'minimum' => PHP_INT_MAX - 2000]), count: 300, seed: 5);
        Assert::true(min($values) >= PHP_INT_MAX - 2000);
        $values = Gen::sample((new SchemaArbitraryCompiler())->compile(['type' => 'integer', 'maximum' => PHP_INT_MIN + 2000]), count: 300, seed: 5);
        Assert::true(max($values) <= PHP_INT_MIN + 2000);
    }

    public function twoDeclaredBoundsThatCrossAreStillRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: integer bounds leave no value');
        (new SchemaArbitraryCompiler())->compile(['type' => 'integer', 'minimum' => 2000, 'maximum' => 1500]);
    }

    public function twoDeclaredNumberBoundsThatCrossAreStillRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation: number bounds leave no value');
        (new SchemaArbitraryCompiler())->compile(['type' => 'number', 'minimum' => 2000.0, 'maximum' => 1500.0]);
    }
}
