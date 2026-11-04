<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ScalarArbitraries::class)]
final class ScalarArbitrariesTest
{
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
}
