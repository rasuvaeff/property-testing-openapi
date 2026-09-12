<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\JsonBodyWitness;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ZooContracts;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(BodyTargets::class)]
#[Covers(JsonBodyWitness::class)]
final class NegativeRequestCaseArbitraryTest
{
    /**
     * A body offered under several media types is generated under each of
     * them, so a mutation that rewrites the JSON value has to be built on
     * the JSON draws alone. Unfiltered, the additional-property case threw
     * out of `Gen::map()` on a multipart draw and relabelled a form body as
     * JSON on a form draw, and the media-type case sent an empty body
     * instead of the valid one it promises to keep (#97).
     */
    #[DataProvider('multiMediaTypeOperationProvider')]
    public function bodyMutationsStayOnTheJsonMediaType(string $operationKey): void
    {
        $contract = ZooContracts::contract();
        $operation = $contract->operation($operationKey);
        $arbitrary = new NegativeRequestCaseArbitrary();
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);

        foreach (range(1, 40) as $seed) {
            $case = $arbitrary->additionalPropertyForOperation($operation)->generate(new Random($seed))->value;
            $body = $case['body'];

            Assert::same($body['mediaType'], 'application/json');
            Assert::same($body['encoding'], 'json');
            Assert::true(is_array($body['value']) && array_key_exists('__openapi_extra_property__', $body['value']));

            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::false($result->isValid());
            Assert::same($result->violations[0]->code, 'request.body.schema');
        }
    }

    /**
     * The media-type case keeps the schema-valid JSON value and changes only
     * the Content-Type, which it cannot do when the draw it was built on
     * carried parts and no value.
     */
    #[DataProvider('multiMediaTypeOperationProvider')]
    public function theMediaTypeCaseKeepsTheValidJsonValue(string $operationKey): void
    {
        $operation = ZooContracts::contract()->operation($operationKey);
        $arbitrary = new NegativeRequestCaseArbitrary();

        foreach (range(1, 40) as $seed) {
            $case = $arbitrary->mediaTypeMismatchForOperation($operation)->generate(new Random($seed))->value;
            $body = $case['body'];

            Assert::same($body['mediaType'], 'application/x-openapi-misuse');
            Assert::same($case['misuse'], ['kind' => 'media-type', 'location' => 'body', 'name' => 'body']);
            Assert::true(is_array($body['value']) && $body['value'] !== []);
        }
    }

    /**
     * The malformed-JSON case is deliberately built on every draw: it
     * replaces the body wholesale and never reads the valid case's value, so
     * filtering would discard most draws of a multi-media-type body for
     * nothing.
     */
    #[DataProvider('multiMediaTypeOperationProvider')]
    public function theMalformedJsonCaseIsBuiltOnAnyDraw(string $operationKey): void
    {
        $operation = ZooContracts::contract()->operation($operationKey);
        $arbitrary = new NegativeRequestCaseArbitrary();

        foreach (range(1, 40) as $seed) {
            $body = $arbitrary->malformedJsonForOperation($operation)->generate(new Random($seed))->value['body'];

            Assert::same($body['mediaType'], 'application/json');
            Assert::same($body['encoding'], 'raw');
            Assert::same($body['value'], '{"malformed":');
        }
    }

    /**
     * PHP stores a decimal-integer property name as an `int` key, so a
     * property the document declares as `"12"` used to be skipped by the
     * witness search on both sides and an operation whose only constrained
     * property is numeric had no constructible body value category at all
     * (#98).
     */
    #[DataProvider('numericPropertyProvider')]
    public function aNumericPropertyNameIsABodyWitnessCandidate(string $method, string $expectedName): void
    {
        $contract = ZooContracts::contract();
        $operation = $contract->operation('numeric.create');
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $arbitrary = new NegativeRequestCaseArbitrary();

        foreach (range(1, 20) as $seed) {
            $case = $arbitrary->{$method}($operation)->generate(new Random($seed))->value;

            Assert::same($case['misuse'], ['kind' => $case['misuse']['kind'], 'location' => 'body', 'name' => $expectedName]);
            Assert::true(array_key_exists($expectedName, (array) $case['body']['value']));

            $result = $contract->validateRequest($materializer->materialize($operation, $case));
            Assert::false($result->isValid());
            Assert::same($result->violations[0]->code, 'request.body.schema');
        }
    }

    public static function numericPropertyProvider(): iterable
    {
        yield 'boundary on "12"' => ['bodyBoundaryMismatchForOperation', '12'];
        yield 'length on "0"' => ['bodyLengthMismatchForOperation', '0'];
        yield 'type on "0"' => ['bodyTypeMismatchForOperation', '0'];
    }

    /**
     * The response side writes the witness with `array_replace()` for the
     * same reason: `array_merge()` renumbers integer keys, so a witness
     * written over `"12"` would land under a fresh index and leave the
     * declared property untouched.
     */
    public function theResponseSideKeepsANumericPropertyInPlace(): void
    {
        $contract = self::numericResponseContract();
        $operation = $contract->operation('numeric.get');
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        $arbitrary = new NegativeResponseCaseArbitrary();

        foreach (range(1, 20) as $seed) {
            $case = $arbitrary->boundaryMismatchForOperation($operation, 200)->generate(new Random($seed))->value;
            $value = (array) $case['body']['value'];

            Assert::same($case['misuse'], ['kind' => 'boundary', 'location' => 'body', 'name' => '12']);
            Assert::same(count($value), 1);
            Assert::true(array_key_exists('12', $value));

            $result = $contract->validateResponse('numeric.get', $materializer->materialize($operation, $case));
            Assert::false($result->isValid());
            Assert::same($result->violations[0]->code, 'response.body.schema');
        }
    }

    private static function numericResponseContract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Numeric', 'version' => '1.0.0'],
            'paths' => ['/numeric' => ['get' => [
                'operationId' => 'numeric.get',
                'responses' => ['200' => [
                    'description' => 'numeric',
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['12'],
                        'additionalProperties' => false,
                        'properties' => ['12' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
                    ]]],
                ]],
            ]]],
        ]);
    }

    public static function multiMediaTypeOperationProvider(): iterable
    {
        yield 'json beside multipart' => ['mixed.create'];
        yield 'json beside form' => ['dual.create'];
    }
}
