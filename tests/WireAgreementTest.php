<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
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
        if (isset($schema['multipleOf']) && extension_loaded('bcmath')) {
            // With bcmath the contract evaluates multipleOf in decimal
            // arithmetic over the double's binary expansion, and its verdict
            // is not one a generator can meet (openapi-contract#151).
            return;
        }
        $contract = $this->parameterContract([['name' => 'v', 'in' => 'query', 'required' => true, 'schema' => $schema]]);

        $this->validCases($contract, 'things.list', 200);
    }

    public static function preciseNumberProvider(): iterable
    {
        yield 'a decimal multiple of a large number' => [['type' => 'number', 'multipleOf' => 0.001, 'minimum' => 1e11, 'maximum' => 1e12]];
        yield 'a decimal multiple past the tolerance of one ulp' => [['type' => 'number', 'multipleOf' => 0.1, 'minimum' => 0, 'maximum' => 10000]];
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
     * Every draw must be rejected by the contract.
     *
     * @param ArbitraryInterface<array<string, mixed>> $arbitrary
     * @return list<array<string, mixed>>
     */
    private function negativeCases(Contract $contract, string $operationKey, ArbitraryInterface $arbitrary, int $draws = self::DRAWS): array
    {
        $operation = $contract->operation($operationKey);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $cases = [];
        foreach (range(1, $draws) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::false($result->isValid(), sprintf('Seed %d accepted: %s', $seed, json_encode($case, JSON_THROW_ON_ERROR)));
            $cases[] = $case;
        }

        return $cases;
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
