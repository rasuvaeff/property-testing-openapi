<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ZooContracts;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(BodyTargets::class)]
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

    public static function multiMediaTypeOperationProvider(): iterable
    {
        yield 'json beside multipart' => ['mixed.create'];
        yield 'json beside form' => ['dual.create'];
    }
}
