<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * The generator and the validator are one oracle: every valid case this
 * package builds for a document must be accepted by `openapi-contract`, and
 * every negative one rejected. Each test here pins a document on which the
 * two used to disagree.
 */
#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(SchemaArbitraryCompiler::class)]
#[Covers(ContainerArbitraries::class)]
#[Covers(CompositionArbitraries::class)]
#[Covers(ScalarArbitraries::class)]
#[Covers(ParameterSchemas::class)]
#[Covers(WireValue::class)]
final class WireAgreementTest
{
    private const int DRAWS = 200;

    /**
     * A `readOnly` property is declared and typed by the contract, and a
     * request should not carry it: the valid phase never emits it, and never
     * generates an undeclared member under its name either — the contract
     * would type-check that member as the declared property.
     */
    public function readOnlyPropertiesAreNeverSentAndTheirNamesStayReserved(): void
    {
        $contract = $this->jsonBodyContract([
            'type' => 'object',
            'required' => ['id'],
            'minProperties' => 1,
            'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]],
        ]);

        foreach ($this->validCases($contract, 'things.create', 300) as $case) {
            Assert::false(array_key_exists('id', (array) ($case['body']['value'] ?? [])));
        }
    }

    /**
     * A JSON media type without a `schema`, or with the `true` schema, admits
     * any value in OpenAPI, and the contract reads both as unconstrained.
     */
    public function aJsonBodyWithoutASchemaIsGeneratedUnconstrained(): void
    {
        foreach ([[], ['schema' => true]] as $definition) {
            $contract = Contract::fromArray([
                'openapi' => '3.1.0',
                'paths' => ['/things' => ['post' => [
                    'operationId' => 'things.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => $definition]],
                    'responses' => ['201' => []],
                ]]],
            ]);

            Assert::same(count($this->validCases($contract, 'things.create', 50)), 50);
        }
    }

    /**
     * A float goes on the wire as the shortest decimal that reads back as the
     * same double, not rounded to fourteen significant digits: the validator
     * checks `multipleOf` and the bounds on the double it parses (#117).
     */
    #[DataProvider('preciseNumberProvider')]
    public function numericParametersSurviveTheWireAtFullPrecision(array $schema): void
    {
        $contract = $this->parameterContract([['name' => 'v', 'in' => 'query', 'required' => true, 'schema' => $schema]]);

        $this->validCases($contract, 'things.list', 200);
    }

    public static function preciseNumberProvider(): iterable
    {
        yield 'a decimal multiple of a large number' => [['type' => 'number', 'multipleOf' => 0.001, 'minimum' => 1e11, 'maximum' => 1e12]];
        yield 'a decimal multiple past the tolerance of one ulp' => [['type' => 'number', 'multipleOf' => 0.1, 'minimum' => 0, 'maximum' => 10000]];
        yield 'a decimal multiple of a decimal at a hundred' => [['type' => 'number', 'multipleOf' => 0.7, 'minimum' => 100, 'maximum' => 200]];
        yield 'a window narrower than fourteen digits' => [['type' => 'number', 'minimum' => 0.123456789012345, 'maximum' => 0.1234567890123456]];
        yield 'an integer beyond fourteen digits' => [['type' => 'integer', 'minimum' => 123456789012345678, 'maximum' => 123456789012345680]];
    }

    #[DataProvider('wireSpellingProvider')]
    public function spellsAScalarTheWayJsonDoes(mixed $value, ?string $expected): void
    {
        Assert::same(WireValue::of($value), $expected);
    }

    public static function wireSpellingProvider(): iterable
    {
        yield 'a fraction that precision=14 rounds' => [846608010056.187, '846608010056.187'];
        yield 'the value just below 1000' => [999.9999999999999, '999.9999999999999'];
        yield 'a whole float' => [10.0, '10'];
        yield 'negative zero' => [-0.0, '-0'];
        yield 'a large magnitude takes the exponent form' => [1e25, '1.0e+25'];
        yield 'a small magnitude takes the exponent form' => [1e-7, '1.0e-7'];
        yield 'an integer' => [-42, '-42'];
        yield 'a string' => ['x', 'x'];
        yield 'booleans' => [true, 'true'];
        yield 'null' => [null, 'null'];
        yield 'infinity has no spelling the grammar admits' => [INF, null];
        yield 'nan has no spelling' => [NAN, null];
        yield 'a container has no spelling' => [['a'], null];
    }

    /**
     * `allowReserved` hands the RFC 3986 reserved characters back raw, except
     * `+`: a raw plus in a query is a space to the validator and to every
     * SAPI (#119).
     */
    public function allowReservedKeepsThePlusEncoded(): void
    {
        $contract = $this->parameterContract([['name' => 'v', 'in' => 'query', 'required' => true, 'allowReserved' => true, 'schema' => ['type' => 'string', 'enum' => ['a+b', 'c d', 'e&f', 'g/h']]]]);

        $seen = [];
        foreach ($this->validCases($contract, 'things.list') as $case) {
            $seen[$case['query']['v']] = true;
        }
        ksort($seen);

        Assert::same(array_keys($seen), ['a+b', 'c d', 'e&f', 'g/h']);
    }

    /**
     * A multipart part declared `application/json` carries the JSON encoding
     * of its value: the validator decodes such a part as JSON (#122).
     */
    public function aJsonMultipartPartIsJsonEncoded(): void
    {
        $contract = $this->multipartContract(
            ['meta' => ['type' => 'string'], 'count' => ['type' => 'integer'], 'note' => ['type' => 'string']],
            ['meta' => ['contentType' => 'application/json'], 'count' => ['contentType' => 'application/vnd.api+json']],
        );

        foreach ($this->validCases($contract, 'uploads.create', 100) as $case) {
            foreach ($case['body']['parts'] ?? [] as $part) {
                if ($part['name'] === 'meta') {
                    Assert::true(str_starts_with($part['value'], '"'), 'a JSON part carries a JSON string');
                }
            }
        }
    }

    /**
     * Every integer is also a number, so `oneOf` over the two is not a plain
     * choice between disjoint branches: a draw from the integer branch would
     * match both and the contract rejects it. The checked path keeps a branch
     * value only when every other branch rejects it (#121).
     */
    public function oneOfOverIntegerAndNumberIsNotTreatedAsDisjoint(): void
    {
        $kinds = [];
        foreach ($this->validCases($this->jsonBodyContract(['oneOf' => [['type' => 'integer'], ['type' => 'number']]]), 'things.create', 60) as $case) {
            $kinds[get_debug_type($case['body']['value'] ?? null)] = true;
        }
        // An unbounded number branch admits every integer, so the only valid
        // values are the non-integral floats.
        Assert::same(array_keys($kinds), ['float']);

        $kinds = [];
        foreach ($this->validCases($this->jsonBodyContract(['oneOf' => [['type' => 'integer', 'minimum' => -5, 'maximum' => 5], ['type' => 'number', 'minimum' => 0, 'maximum' => 10, 'multipleOf' => 0.5]]]), 'things.create', 120) as $case) {
            $value = $case['body']['value'] ?? null;
            $kinds[is_int($value) ? ($value < 0 ? 'negative int' : 'int') : 'float'] = true;
        }
        ksort($kinds);
        // The number branch refuses the negative integers, so those stay valid
        // for the integer branch; 0..5 are admitted by both and never drawn.
        Assert::same(array_keys($kinds), ['float', 'negative int']);

        // `0.7` over a wide integer branch: the old float copy of the verdict
        // kept 58254 on the integer branch and the contract rejected the case
        // (#132). The verdict is the contract's now.
        $kinds = [];
        foreach ($this->validCases($this->jsonBodyContract(['oneOf' => [['type' => 'integer', 'minimum' => -100000, 'maximum' => 100000], ['type' => 'number', 'minimum' => 0, 'maximum' => 100000, 'multipleOf' => 0.7]]]), 'things.create', 300) as $case) {
            $value = $case['body']['value'] ?? null;
            $kinds[is_int($value) ? ($value < 0 ? 'negative int' : 'int') : 'float'] = true;
        }
        ksort($kinds);
        Assert::same(array_keys($kinds), ['float', 'int', 'negative int']);
    }

    /**
     * A generated decimal multiple is the decimal multiple the contract judges
     * it to be — for every index in the range and every multiple a document
     * spells (#133).
     */
    #[Property(runs: 300)]
    public function everyGeneratedMultipleIsOneToTheContract(float $multiple, int $maximum): void
    {
        $schema = ['type' => 'number', 'minimum' => -$maximum, 'maximum' => $maximum, 'multipleOf' => $multiple];
        foreach (Gen::sample((new SchemaArbitraryCompiler())->compile($schema), count: 20, seed: $maximum) as $value) {
            Assert::true(is_float($value) && SchemaCheck::isMultipleOf($value, $multiple), sprintf('%s is a multiple of %s', json_encode($value), json_encode($multiple)));
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function everyGeneratedMultipleIsOneToTheContractGenerators(): array
    {
        return [
            'multiple' => Gen::elements([0.1, 0.3, 0.7, 0.07, 0.001, 2.5, 0.25, 0.125, 1.5]),
            'maximum' => Gen::intBetween(1, 1_000_000_000),
        ];
    }

    public function aMultipleTheDoubleCannotSpellUpToTheBoundIsRefused(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation for operation "things.list", query parameter "v": number multipleOf 0.001 cannot be spelled exactly up to 100000000000000: the multiples need more than the 15 significant digits a double holds');

        (new RequestCaseArbitrary())->forOperation($this->parameterContract([['name' => 'v', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'number', 'multipleOf' => 0.001, 'minimum' => 0, 'maximum' => 1e14]]])->operation('things.list'));
    }

    public function oneOfOverIntegerAndNumberFailsClosedWhenNoValueCanBeKeptApart(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation for operation "things.create", request body "application/json": oneOf over integer and number admits no value exactly one branch accepts');

        (new RequestCaseArbitrary())->forOperation($this->jsonBodyContract(['oneOf' => [['type' => 'integer', 'minimum' => 2, 'maximum' => 2], ['type' => 'number', 'multipleOf' => 2]]])->operation('things.create'));
    }

    /**
     * Three legal documents reached a run-time `GenerationExhausted` the
     * package's own rules call a defect (#123): a header enum outside ASCII,
     * a path pattern that always carries a slash, an object whose optionals
     * outnumber `maxProperties`. The first and the third generate; the
     * second is refused at compile time, by name.
     */
    public function legalDocumentsNeverExhaustAtRunTime(): void
    {
        $header = $this->parameterContract([['name' => 'X-Lang', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['žluť', 'New York']]]]);
        $seen = [];
        foreach ($this->validCases($header, 'things.list', 40) as $case) {
            $seen[$case['headers']['X-Lang']] = true;
        }
        ksort($seen);
        Assert::same(array_keys($seen), ['New York', 'žluť']);

        $properties = [];
        foreach (range('a', 'l') as $name) {
            $properties[$name] = ['type' => 'integer'];
        }
        foreach ($this->validCases($this->jsonBodyContract(['type' => 'object', 'properties' => $properties, 'maxProperties' => 1, 'additionalProperties' => false]), 'things.create', 150) as $case) {
            Assert::true(count($case['body']['value'] ?? []) <= 1);
        }
        foreach ($this->validCases($this->jsonBodyContract(['type' => 'object', 'properties' => $properties, 'minProperties' => 10, 'maxProperties' => 11, 'additionalProperties' => false]), 'things.create', 50) as $case) {
            $count = count($case['body']['value'] ?? []);
            Assert::true($count >= 10 && $count <= 11);
        }
    }

    #[DataProvider('compileTimeRefusalProvider')]
    public function unsatisfiableParametersAreRefusedAtCompileTime(array $parameter, string $message, string $path = '/things'): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new RequestCaseArbitrary())->forOperation($this->parameterContract([$parameter], $path)->operation('things.list'));
    }

    public static function compileTimeRefusalProvider(): iterable
    {
        yield 'a path pattern that always carries a slash' => [
            ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^[a-z]+/[a-z]+$']],
            'Unsupported OpenAPI schema generation for operation "things.list", path parameter "slug": no value the pattern admits can be carried by a template segment',
            '/things/{slug}',
        ];
        yield 'a header pattern that always starts with a space' => [
            ['name' => 'X-Pad', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'pattern' => '^ [a-z]+$']],
            'Unsupported OpenAPI schema generation for operation "things.list", header parameter "X-Pad": no value the pattern admits can be carried by a field value',
        ];
        yield 'a header enum no member of which is read as sent' => [
            ['name' => 'X-Pad', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => [' a', 'b ']]],
            'Unsupported OpenAPI schema generation for operation "things.list", header parameter "X-Pad": no header enum member can be carried by a field value as sent',
        ];
        yield 'uniqueItems over an integer domain smaller than minItems' => [
            ['name' => 'ids', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'array', 'uniqueItems' => true, 'minItems' => 3, 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1]]],
            'Unsupported OpenAPI schema generation for operation "things.list", query parameter "ids": uniqueItems cannot fill minItems from the finite item domain',
        ];
    }

    /**
     * A nested exploded form object is written as flat pairs, so the
     * contract claims only its declared members for it: an undeclared member
     * would be read as a top-level one. It is generated without extras, and
     * a `minProperties` its declared properties cannot meet is refused at
     * compile time (#120).
     */
    public function aNestedExplodedFormObjectCarriesOnlyDeclaredMembers(): void
    {
        $contract = $this->formBodyContract(['type' => 'object', 'required' => ['t'], 'additionalProperties' => false, 'properties' => [
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
            't' => ['type' => 'object', 'minProperties' => 1, 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'string', 'maxLength' => 3]]],
        ]]);

        foreach ($this->validCases($contract, 'things.create', 200) as $case) {
            Assert::same(array_diff(array_keys($case['body']['value']['t']), ['x', 'y']), []);
        }
    }

    public function aNestedExplodedFormObjectThatNeedsUndeclaredMembersFailsClosed(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation for operation "things.create", request body "application/x-www-form-urlencoded": form property "t" is an exploded object whose minProperties 1 cannot be met by its 0 declared properties, and its wire form carries no undeclared member');

        (new RequestCaseArbitrary())->forOperation($this->formBodyContract(['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'],
            'plain' => ['type' => 'object', 'properties' => ['k' => ['type' => 'string']]],
            'flat' => ['type' => 'object', 'minProperties' => 1],
            't' => ['type' => 'object', 'minProperties' => 1],
        ], 'required' => ['flat']], ['flat' => ['explode' => false]])->operation('things.create'));
    }

    /**
     * A comma is a member separator only in a list or object header; a
     * scalar header carries it as sent, and an object header drops a member
     * whose key or value carries one (#129).
     */
    public function aCommaSeparatesOnlyTheMembersOfAListOrObjectHeader(): void
    {
        $contract = $this->parameterContract([
            ['name' => 'X-Expr', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['a,b']]],
            ['name' => 'X-Map', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => true, 'schema' => ['type' => 'object', 'minProperties' => 1, 'properties' => ['k' => ['type' => 'string', 'enum' => ['x,y', 'z']]], 'additionalProperties' => false]],
        ]);

        foreach ($this->validCases($contract, 'things.list', 40) as $case) {
            Assert::same($case['headers']['X-Expr'], 'a,b');
            Assert::same($case['headers']['X-Map'], ['k' => 'z']);
        }
    }

    /**
     * A JSON part is written with slashes and non-ASCII unescaped, as the
     * JSON body encoder writes a body (#122).
     */
    public function aJsonMultipartPartKeepsSlashesAndUnicodeUnescaped(): void
    {
        $contract = $this->multipartContract(['meta' => ['type' => 'string', 'const' => 'a/é']], ['meta' => ['contentType' => 'application/json']]);

        foreach ($this->validCases($contract, 'uploads.create', 3) as $case) {
            Assert::same($case['body']['parts'][0]['value'] ?? null, '"a/é"');
        }
    }

    /**
     * Only an exploded object is written as flat pairs: with `explode: false`
     * the object travels as one `name=k,v,k,v` value the contract attributes
     * to it, undeclared members included, so nothing is stripped (#120).
     */
    public function aNonExplodedFormObjectKeepsItsUndeclaredMembers(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/things' => ['post' => [
                'operationId' => 'things.create',
                'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => [
                    'schema' => ['type' => 'object', 'required' => ['t'], 'properties' => ['t' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 2, 'additionalProperties' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 3]]]],
                    'encoding' => ['t' => ['explode' => false]],
                ]]],
                'responses' => ['201' => []],
            ]]],
        ]);

        foreach ($this->validCases($contract, 'things.create', 30) as $case) {
            Assert::true(count($case['body']['value']['t']) >= 1);
        }
    }

    /**
     * An exploded object whose declared properties exactly meet its
     * `minProperties` is generated, and one without any `minProperties` is
     * generated too — only a floor the declared members cannot reach is
     * refused (#120).
     */
    public function anExplodedFormObjectMeetingItsFloorWithDeclaredMembersIsGenerated(): void
    {
        $contract = $this->formBodyContract(['type' => 'object', 'required' => ['t', 'u'], 'properties' => [
            't' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['x' => ['type' => 'integer'], 'y' => ['type' => 'integer']]],
            'u' => ['type' => 'object', 'properties' => ['z' => ['type' => 'integer']]],
            'w' => ['type' => 'object'],
        ]]);

        foreach ($this->validCases($contract, 'things.create', 30) as $case) {
            $names = array_keys($case['body']['value']['t']);
            sort($names);
            Assert::same($names, ['x', 'y']);
        }
    }

    public function aPartUnderAMediaTypeThatIsNeitherTextNorJsonFailsClosed(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Multipart property "meta" declares content type "application/xml", which this generator can write neither as text nor as JSON');

        $contract = $this->multipartContract(['meta' => ['type' => 'string']], ['meta' => ['contentType' => 'application/xml']]);

        (new RequestCaseArbitrary())->forOperation($contract->operation('uploads.create'))->generate(new Random(1));
    }

    /**
     * Every draw must be accepted by the contract; the cases are returned so
     * a test can pin what they carry as well.
     *
     * @return list<array<string, mixed>>
     */
    private function validCases(Contract $contract, string $operationKey, int $draws = self::DRAWS): array
    {
        $operation = $contract->operation($operationKey);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $cases = [];
        foreach (range(1, $draws) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::true($result->isValid(), sprintf('Seed %d: %s', $seed, json_encode([$case, $result->violations], JSON_THROW_ON_ERROR)));
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, array<string, mixed>> $encoding
     */
    private function formBodyContract(array $schema, array $encoding = []): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/things' => ['post' => [
                'operationId' => 'things.create',
                'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => $schema, 'encoding' => $encoding]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    /** @param array<string, mixed> $schema */
    private function jsonBodyContract(array $schema, string $version = '3.1.0'): Contract
    {
        return Contract::fromArray([
            'openapi' => $version,
            'paths' => ['/things' => ['post' => [
                'operationId' => 'things.create',
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $parameters
     */
    private function parameterContract(array $parameters, string $path = '/things'): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [$path => ['get' => [
                'operationId' => 'things.list',
                'parameters' => $parameters,
                'responses' => ['200' => []],
            ]]],
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, array<string, mixed>> $encoding
     */
    private function multipartContract(array $properties, array $encoding): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/uploads' => ['post' => [
                'operationId' => 'uploads.create',
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties],
                    'encoding' => $encoding,
                ]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }
}
