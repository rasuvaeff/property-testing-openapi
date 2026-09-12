<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\FormatRefusal;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ParameterTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ResponseTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\WitnessCheck;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(WitnessCheck::class)]
#[Covers(FormatRefusal::class)]
#[Covers(ParameterTargets::class)]
final class WitnessCheckTest
{
    private WitnessCheck $check;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->check = new WitnessCheck();
    }

    /**
     * The property the three keyword lists approximated: the witness is
     * rejected by the schema and accepted by the schema without the
     * category's keywords.
     *
     * The direction is forwarded to the contract rather than exercised here:
     * the rewrite it selects drops `readOnly`/`writeOnly` *properties* of an
     * object, and a witness is always judged against one property's own
     * schema. The callers differ — {@see BodyTargets} asks about a request,
     * {@see ResponseTargets} about a response — and the contract's own suite
     * pins what each answers.
     */
    #[DataProvider('discriminationProvider')]
    public function aWitnessEarnsItsCategoryOrIsRefused(array $schema, string $kind, mixed $value, bool $expected): void
    {
        Assert::same((new WitnessCheck())->discriminates($value, $schema, $kind, SchemaDialect::OpenApi31, SchemaDirection::Request), $expected);
    }

    public static function discriminationProvider(): iterable
    {
        $email = ['type' => 'string', 'format' => 'email', 'maxLength' => 255];

        yield 'format witness inside the length window' => [$email, 'format', 'not-an-email', true];
        yield 'format witness outside the length window' => [['type' => 'string', 'format' => 'email', 'maxLength' => 5], 'format', 'not-an-email', false];
        yield 'length witness also breaks the format' => [$email, 'length', str_repeat('a', 256), false];
        yield 'length witness inside a pattern' => [['type' => 'string', 'minLength' => 3, 'pattern' => '^a+$'], 'length', 'aa', true];
        yield 'length witness outside a pattern' => [['type' => 'string', 'maxLength' => 3, 'pattern' => '^a{1,3}$'], 'length', 'aaaa', false];
        yield 'marker breaks an integer enum and its type' => [['type' => 'integer', 'enum' => [1, 2, 3]], 'enum', '__openapi_misuse__', false];
        yield 'typed alternative breaks only the enum' => [['type' => 'integer', 'enum' => [1, 2, 3]], 'enum', 0, true];
        yield 'marker longer than a string enum bound' => [['type' => 'string', 'enum' => ['a', 'b'], 'maxLength' => 5], 'enum', '__openapi_misuse__', false];
        yield 'short alternative breaks only the enum' => [['type' => 'string', 'enum' => ['a', 'b'], 'maxLength' => 5], 'enum', 'z', true];
        yield 'boundary that the enum also forbids' => [['type' => 'integer', 'enum' => [1, 2, 3], 'maximum' => 3], 'boundary', 4, false];
        yield 'boundary alone' => [['type' => 'integer', 'minimum' => 1, 'maximum' => 20], 'boundary', 0, true];
        yield 'array over maxItems with typed items' => [['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']], 'length', [0, 0, 0], true];
        yield 'array of nulls also breaks items' => [['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']], 'length', [null, null, null], false];
        yield 'a schema the dialect cannot read fails closed' => [['type' => 'string', 'nullable' => true, 'maxLength' => 3], 'length', 'aaaa', false];
        yield 'a value that satisfies the schema is no witness' => [['type' => 'integer', 'maximum' => 9], 'boundary', 5, false];
    }

    /**
     * An object-valued witness is judged as the backend reads JSON, not as
     * PHP holds it: an associative array is a JSON array no `type: object`
     * schema admits, so without the round trip no such witness ever
     * discriminates.
     */
    public function anObjectWitnessIsJudgedAsJson(): void
    {
        $schema = ['type' => 'array', 'maxItems' => 1, 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]];

        Assert::true($this->check->discriminates([['id' => 1], ['id' => 2]], $schema, 'length', SchemaDialect::OpenApi31, SchemaDirection::Request));
    }

    /**
     * A refusal used to read the same whether the schema declared no format
     * at all or declared one this package holds no witness for — opposite
     * situations for a document owner, since the second is a gap here rather
     * than in the document (#103).
     */
    #[DataProvider('formatRefusalProvider')]
    public function aFormatRefusalNamesItsCause(array $schema, ?FormatRefusal $expected): void
    {
        Assert::same((new SchemaProbe())->formatRefusal($schema), $expected);
    }

    public static function formatRefusalProvider(): iterable
    {
        yield 'no format declared' => [['type' => 'string', 'maxLength' => 3], FormatRefusal::NotDeclared];
        yield 'format is not a string' => [['type' => 'string', 'format' => ['email']], FormatRefusal::NotDeclared];
        yield 'unsupported format' => [['type' => 'string', 'format' => 'hostname'], FormatRefusal::Unsupported];
        yield 'url is deliberately unsupported' => [['type' => 'string', 'format' => 'url'], FormatRefusal::Unsupported];
        yield 'supported format on a non-string' => [['type' => 'integer', 'format' => 'int64'], FormatRefusal::Unsupported];
        yield 'supported format on a number type' => [['type' => 'integer', 'format' => 'date'], FormatRefusal::NotAString];
        yield 'no refusal' => [['type' => 'string', 'format' => 'email'], null];
        yield 'no refusal beside a length bound' => [['type' => 'string', 'format' => 'email', 'maxLength' => 255], null];
    }

    /**
     * And the refusal is observable where a user meets it: the exception that
     * says no format case could be built names the formats no witness is held
     * for, so the right response — an issue here, not an edit to the document
     * — is visible without reading the source.
     */
    public function anUnsupportedFormatIsNamedInTheRefusal(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [
            ['name' => 'host', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string', 'format' => 'hostname'], 'specPointer' => '#/h'],
            ['name' => 'when', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string', 'format' => 'duration'], 'specPointer' => '#/w'],
        ]);

        try {
            (new ParameterTargets())->formatMismatch($operation);
        } catch (UnsupportedGeneration $exception) {
            Assert::string($exception->getMessage())->contains('no witness is held for format "hostname", "duration"');

            return;
        }

        Assert::true(actual: false, message: 'Expected a refusal naming the unsupported formats');
    }

    /**
     * And only that refusal: a category that has nothing to do with formats
     * says nothing about them.
     */
    public function onlyAFormatRefusalNamesFormats(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [
            ['name' => 'host', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string', 'format' => 'hostname'], 'specPointer' => '#/h'],
        ]);

        try {
            (new ParameterTargets())->boundaryMismatch($operation);
        } catch (UnsupportedGeneration $exception) {
            Assert::string($exception->getMessage())->contains('has no numeric parameter with a constructible boundary mismatch');
            Assert::false(str_contains($exception->getMessage(), 'no witness is held'));

            return;
        }

        Assert::true(actual: false, message: 'Expected a boundary refusal');
    }

    /** A supported format is not named: it is disprovable, so nothing is skipped for it. */
    public function aSupportedFormatIsNotNamedAsMissing(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [
            ['name' => 'when', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'integer', 'format' => 'date'], 'specPointer' => '#/w'],
        ]);

        try {
            (new ParameterTargets())->formatMismatch($operation);
        } catch (UnsupportedGeneration $exception) {
            // `date` is supported; it is the integer type that makes the
            // witness inapplicable, and that is not a gap in this package.
            Assert::false(str_contains($exception->getMessage(), 'no witness is held'));

            return;
        }

        Assert::true(actual: false, message: 'Expected a format refusal');
    }

    /** Repeating a question answers it identically; the memo is not a cache that forgets. */
    #[Property(runs: 200)]
    public function theAnswerDoesNotDependOnHowOftenItIsAsked(int $maximum, int $value): void
    {
        $schema = ['type' => 'integer', 'maximum' => $maximum];
        $first = $this->check->discriminates($value, $schema, 'boundary', SchemaDialect::OpenApi31, SchemaDirection::Request);

        Classify::cover(condition: $first, label: 'discriminates', minPercent: 10.0);
        Classify::cover(condition: !$first, label: 'refused', minPercent: 10.0);

        Assert::same($this->check->discriminates($value, $schema, 'boundary', SchemaDialect::OpenApi31, SchemaDirection::Request), $first);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function theAnswerDoesNotDependOnHowOftenItIsAskedGenerators(): array
    {
        return ['maximum' => Gen::intBetween(0, 20), 'value' => Gen::intBetween(0, 40)];
    }

    /** @return iterable<string, array{int, int}> */
    public static function theAnswerDoesNotDependOnHowOftenItIsAskedExamples(): iterable
    {
        yield 'just above' => [5, 6];
        yield 'on the bound' => [5, 5];
    }

    /**
     * The memo is keyed by everything that changes the answer. A key missing
     * one of them returns a previous answer to a different question — which
     * no assertion on a single call can see.
     */
    #[DataProvider('memoKeyProvider')]
    public function theMemoDistinguishesEveryPartOfTheQuestion(
        array $firstSchema,
        string $firstKind,
        mixed $firstValue,
        SchemaDialect $firstDialect,
        SchemaDirection $firstDirection,
        array $secondSchema,
        string $secondKind,
        mixed $secondValue,
        SchemaDialect $secondDialect,
        SchemaDirection $secondDirection,
    ): void {
        $first = $this->check->discriminates($firstValue, $firstSchema, $firstKind, $firstDialect, $firstDirection);
        $second = $this->check->discriminates($secondValue, $secondSchema, $secondKind, $secondDialect, $secondDirection);

        Assert::true($first);
        Assert::false($second);
    }

    public static function memoKeyProvider(): iterable
    {
        $nullable = ['type' => 'string', 'nullable' => true, 'maxLength' => 3];
        $bounded = ['type' => 'integer', 'maximum' => 9];
        $directional = ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'integer', 'writeOnly' => true]]];

        // `nullable` is a keyword under 3.0 and an unreadable schema under
        // 3.1, so the same question answers differently by dialect alone.
        yield 'dialect' => [$nullable, 'length', 'aaaa', SchemaDialect::OpenApi30, SchemaDirection::Request,
            $nullable, 'length', 'aaaa', SchemaDialect::OpenApi31, SchemaDirection::Request];
        // A `writeOnly` property is dropped from a response along with its
        // `required` entry, so the same object stops being rejected.
        yield 'direction' => [$directional, 'type', 'not-a-object', SchemaDialect::OpenApi31, SchemaDirection::Request,
            $directional, 'type', (object) [], SchemaDialect::OpenApi31, SchemaDirection::Response];
        yield 'kind' => [$bounded, 'boundary', 10, SchemaDialect::OpenApi31, SchemaDirection::Request,
            $bounded, 'type', 10, SchemaDialect::OpenApi31, SchemaDirection::Request];
        yield 'value' => [$bounded, 'boundary', 10, SchemaDialect::OpenApi31, SchemaDirection::Request,
            $bounded, 'boundary', 5, SchemaDialect::OpenApi31, SchemaDirection::Request];
        yield 'schema' => [$bounded, 'boundary', 10, SchemaDialect::OpenApi31, SchemaDirection::Request,
            ['type' => 'integer', 'maximum' => 99], 'boundary', 10, SchemaDialect::OpenApi31, SchemaDirection::Request];
    }

    /**
     * A nested associative array becomes an object at every depth, and a list
     * stays a list — the distinction the backend makes and PHP does not.
     */
    public function jsonShapesSurviveAtEveryDepth(): void
    {
        $schema = [
            'type' => 'array',
            'maxItems' => 1,
            'items' => [
                'type' => 'object',
                'required' => ['tags', 'meta'],
                'properties' => [
                    'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'meta' => ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string']]],
                ],
            ],
        ];
        $item = ['tags' => ['a'], 'meta' => ['k' => 'v']];

        Assert::true($this->check->discriminates([$item, $item], $schema, 'length', SchemaDialect::OpenApi31, SchemaDirection::Request));
    }
}
