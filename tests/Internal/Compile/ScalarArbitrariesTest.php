<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal\Compile;

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
     * `reach * 10 <= 2^53` — just above `9.0072e14` for the bound.
     */
    #[DataProvider('decimalReachProvider')]
    public function aDecimalMultipleIsRefusedExactlyPastTheDoublesPrecision(float $maximum, bool $accepted): void
    {
        $schema = ['type' => 'number', 'minimum' => 0, 'maximum' => $maximum, 'multipleOf' => 0.1];
        if (!$accepted) {
            Expect::exception(UnsupportedGeneration::class);
        }

        // The verdict only. Near this reach a generated multiple can land
        // above `maximum` (850000000000000.1 for 8.5e14), which the contract
        // rejects: a defect of its own, reported separately.
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 5, seed: 1) as $value) {
            Assert::true(is_float($value));
        }
    }

    public static function decimalReachProvider(): iterable
    {
        yield 'well inside' => [8.5e14, true];
        yield 'exactly at 2^53 after scaling' => [900719925474099.2, true];
        yield 'past it' => [9.5e14, false];
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
