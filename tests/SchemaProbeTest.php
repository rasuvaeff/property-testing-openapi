<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * The candidate lists themselves, pinned exactly. A probe offers values and
 * {@see \Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\WitnessCheck}
 * decides which one earns its category, so what each probe proposes has to be
 * visible on its own — an offer that silently changes shape is a category
 * that silently changes what it tests.
 */
#[Test]
#[Covers(SchemaProbe::class)]
final class SchemaProbeTest
{
    private SchemaProbe $probe;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->probe = new SchemaProbe();
    }

    /** A schema with no `type` declares none, rather than one named `null`. */
    public function declaredTypesAreEmptyWithoutTheKeyword(): void
    {
        Assert::same($this->probe->declaredTypes([]), []);
        Assert::same($this->probe->declaredTypes(['type' => 'string']), ['string']);
        Assert::same($this->probe->declaredTypes(['type' => ['string', 'null']]), ['string', 'null']);
    }

    #[DataProvider('outOfRangeProvider')]
    public function outOfRangeValuesSitJustOutsideEachBound(array $schema, array $expected): void
    {
        Assert::same($this->probe->outOfRangeValues($schema), $expected);
    }

    public static function outOfRangeProvider(): iterable
    {
        yield 'integer minimum' => [['type' => 'integer', 'minimum' => 1], [0]];
        yield 'integer maximum' => [['type' => 'integer', 'maximum' => 9], [10]];
        yield 'both bounds, both sides offered' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 9], [0, 10]];
        yield 'exclusive minimum is the bound itself' => [['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true], [1]];
        yield 'exclusive maximum is the bound itself' => [['type' => 'integer', 'maximum' => 9, 'exclusiveMaximum' => true], [9]];
        yield 'number bounds are floats' => [['type' => 'number', 'minimum' => 0.5, 'maximum' => 9.5], [-0.5, 10.5]];
        yield 'an integer bound under a number type is still a float' => [['type' => 'number', 'maximum' => 5], [6.0]];
        yield 'a float bound under an integer type yields nothing' => [['type' => 'integer', 'maximum' => 5.5], []];
        yield 'no bound' => [['type' => 'integer'], []];
        yield 'non-numeric type' => [['type' => 'string', 'maximum' => 5], []];
        yield 'a non-numeric bound is ignored' => [['type' => 'integer', 'maximum' => '9'], []];
        // Stepping outside the bound has to stay representable. At the
        // extremes there is no next value, and offering the bound itself
        // would be offering a value the schema admits.
        yield 'a minimum at the integer floor has nothing below it' => [['type' => 'number', 'minimum' => PHP_INT_MIN], []];
        yield 'a maximum at the integer ceiling has nothing above it' => [['type' => 'number', 'maximum' => PHP_INT_MAX], []];
        // The float candidate is skipped and the search carries on to the
        // integer one rather than stopping at the first unusable bound.
        yield 'a float minimum under an integer type does not end the search' => [['type' => 'integer', 'minimum' => 0.5, 'maximum' => 9], [10]];
    }

    #[DataProvider('outOfLengthProvider')]
    public function outOfLengthValuesSitJustOutsideEachLengthBound(array $schema, array $expected): void
    {
        Assert::same($this->probe->outOfLengthValues($schema), $expected);
    }

    public static function outOfLengthProvider(): iterable
    {
        yield 'below minLength' => [['type' => 'string', 'minLength' => 3], ['aa']];
        yield 'above maxLength' => [['type' => 'string', 'maxLength' => 3], ['aaaa']];
        yield 'both bounds, both sides offered' => [['type' => 'string', 'minLength' => 3, 'maxLength' => 5], ['aa', 'aaaaaa']];
        // A string below `minLength: 1` is the empty one, which materializes
        // as an absent component rather than a short one.
        yield 'minLength 1 offers nothing below it' => [['type' => 'string', 'minLength' => 1], []];
        yield 'maxLength 0 offers one character' => [['type' => 'string', 'maxLength' => 0], ['a']];
        yield 'a bound past the construction budget' => [['type' => 'string', 'minLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH + 1], []];
        yield 'maxLength at the budget' => [['type' => 'string', 'maxLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH], []];
        yield 'minLength at the budget is still constructible' => [
            ['type' => 'string', 'minLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH],
            [str_repeat('a', SchemaProbe::MAX_CONSTRUCTED_LENGTH - 1)],
        ];
        yield 'non-string type' => [['type' => 'integer', 'maxLength' => 3], []];
        yield 'no bound' => [['type' => 'string'], []];
        // A competing keyword is no longer a refusal here: whether the witness
        // trips it instead of the length is decided by validating it (#102).
        yield 'a pattern no longer refuses' => [['type' => 'string', 'maxLength' => 3, 'pattern' => '^a+$'], ['aaaa']];
        yield 'a format no longer refuses' => [['type' => 'string', 'maxLength' => 3, 'format' => 'email'], ['aaaa']];
    }

    #[DataProvider('formatWitnessProvider')]
    public function formatWitnessesAreHeldForSevenFormats(array $schema, array $expected): void
    {
        Assert::same($this->probe->formatWitnesses($schema), $expected);
    }

    public static function formatWitnessProvider(): iterable
    {
        yield 'uuid' => [['type' => 'string', 'format' => 'uuid'], ['not-a-uuid']];
        yield 'email' => [['type' => 'string', 'format' => 'email'], ['not-an-email']];
        yield 'ipv4' => [['type' => 'string', 'format' => 'ipv4'], ['not-an-ipv4']];
        yield 'uri' => [['type' => 'string', 'format' => 'uri'], [':']];
        yield 'uri-reference' => [['type' => 'string', 'format' => 'uri-reference'], ['%']];
        yield 'date' => [['type' => 'string', 'format' => 'date'], ['not-a-date']];
        yield 'date-time' => [['type' => 'string', 'format' => 'date-time'], ['not-a-date-time']];
        yield 'a length bound no longer refuses' => [['type' => 'string', 'format' => 'email', 'maxLength' => 255], ['not-an-email']];
        yield 'unsupported format' => [['type' => 'string', 'format' => 'hostname'], []];
        yield 'url is deliberately unsupported' => [['type' => 'string', 'format' => 'url'], []];
        yield 'no format' => [['type' => 'string'], []];
        yield 'non-string format value' => [['type' => 'string', 'format' => ['email']], []];
        yield 'non-string type' => [['type' => 'integer', 'format' => 'date'], []];
    }

    #[DataProvider('alternativeProvider')]
    public function alternativesAreTypedAndShortestFirst(array $schema, array $forbidden, array $expected): void
    {
        Assert::same($this->probe->alternatives($schema, $forbidden), $expected);
    }

    public static function alternativeProvider(): iterable
    {
        yield 'integers' => [['type' => 'integer'], [], [0, 1, -1, 2, 7, 42, -99]];
        yield 'integers minus the forbidden ones' => [['type' => 'integer'], [0, 1], [-1, 2, 7, 42, -99]];
        yield 'numbers are floats' => [['type' => 'number'], [], [0.0, 1.0, -1.0, 2.0, 7.0, 42.0, -99.0]];
        yield 'booleans' => [['type' => 'boolean'], [true], [false]];
        yield 'strings start at one character' => [['type' => 'string', 'maxLength' => 2], [], ['a', 'z', 'aa', 'zz']];
        yield 'strings honour minLength' => [['type' => 'string', 'minLength' => 2, 'maxLength' => 2], [], ['aa', 'zz']];
        yield 'maxLength 0 admits only the empty string' => [['type' => 'string', 'maxLength' => 0], [], ['']];
        yield 'a forbidden string is dropped' => [['type' => 'string', 'maxLength' => 1], ['a'], ['z']];
        yield 'an untyped schema is treated as a string' => [['maxLength' => 1], [], ['a', 'z']];
    }

    /**
     * The window keeps a pattern witness from tripping a length bound instead
     * of the pattern.
     */
    #[DataProvider('patternWindowProvider')]
    public function patternConstraintsReturnTheLengthWindow(array $schema, ?array $expected): void
    {
        Assert::same($this->probe->patternConstraints($schema), $expected);
    }

    public static function patternWindowProvider(): iterable
    {
        yield 'no bounds opens the whole budget' => [
            ['type' => 'string', 'pattern' => '^a+$'],
            ['pattern' => '^a+$', 'minLength' => 0, 'maxLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH],
        ];
        yield 'declared bounds narrow it' => [
            ['type' => 'string', 'pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 5],
            ['pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 5],
        ];
        yield 'a bound past the budget is clamped' => [
            ['type' => 'string', 'pattern' => '^a+$', 'maxLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH + 10],
            ['pattern' => '^a+$', 'minLength' => 0, 'maxLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH],
        ];
    }

    /** A format the probe cannot disprove is named, so the gap is reviewable rather than silent. */
    #[DataProvider('unsupportedFormatProvider')]
    public function unsupportedFormatNamesWhatCannotBeDisproved(array $schema, ?string $expected): void
    {
        Assert::same($this->probe->unsupportedFormat($schema), $expected);
    }

    public static function unsupportedFormatProvider(): iterable
    {
        yield 'unsupported' => [['type' => 'string', 'format' => 'hostname'], 'hostname'];
        yield 'supported' => [['type' => 'string', 'format' => 'email'], null];
        yield 'none declared' => [['type' => 'string'], null];
        yield 'empty' => [['type' => 'string', 'format' => ''], null];
        yield 'not a string' => [['type' => 'string', 'format' => 7], null];
    }
}
