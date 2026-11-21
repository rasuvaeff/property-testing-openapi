<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RawBodies;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Bodies sent as they are, `text/plain` and `application/octet-stream`, on
 * both sides of the exchange (#170).
 */
#[Test]
#[Covers(RawBodies::class)]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(ResponseCaseArbitrary::class)]
final class RawBodiesTest
{
    #[DataProvider('rawMediaProvider')]
    public function aRawRequestBodyIsOneTheContractAccepts(string $mediaType, array $definition, ?int $maxLength): void
    {
        $contract = $this->contract(['required' => true, 'content' => [$mediaType => $definition]], ['204' => ['description' => 'none']]);
        $operation = $contract->operation('op');
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);

        foreach (range(1, 40) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $body = $case['body'];

            Assert::same([$body['mediaType'] ?? null, $body['encoding'] ?? null], [$mediaType, 'raw']);
            Assert::true(is_string($body['value'] ?? null) && $body['value'] !== '');
            if ($maxLength !== null) {
                Assert::true(mb_strlen((string) $body['value']) <= $maxLength);
            }
            $request = $materializer->materialize($operation, $case);
            Assert::same((string) $request->getBody(), $body['value']);
            Assert::true($contract->validateRequest($request)->isValid(), (string) $request->getBody());
        }
    }

    #[DataProvider('rawMediaProvider')]
    public function aRawResponseBodyIsOneTheContractAccepts(string $mediaType, array $definition): void
    {
        $contract = $this->contract([], ['200' => ['description' => 'raw', 'content' => [$mediaType => $definition]]], method: 'get');
        $operation = $contract->operation('op');
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        $arbitrary = (new ResponseCaseArbitrary())->forOperation($operation, 200);

        foreach (range(1, 40) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;

            Assert::same([$case['body']['mediaType'] ?? null, $case['body']['encoding'] ?? null], [$mediaType, 'raw']);
            Assert::true(is_string($case['body']['value'] ?? null) && $case['body']['value'] !== '');
            Assert::true($contract->validateResponse('op', $materializer->materialize($operation, $case))->isValid());
        }
    }

    public static function rawMediaProvider(): iterable
    {
        yield 'text without a schema' => ['text/plain', [], 64];
        yield 'text with the true schema' => ['text/plain', ['schema' => true], 64];
        yield 'text with a string schema' => ['text/plain', ['schema' => ['type' => 'string', 'maxLength' => 5]], 5];
        yield 'text with a pattern' => ['text/plain; charset=utf-8', ['schema' => ['type' => 'string', 'pattern' => '^v[0-9]{1,3}$']], 4];
        yield 'text with a declared minimum' => ['text/plain', ['schema' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3]], 3];
        yield 'octet stream as binary' => ['application/octet-stream', ['schema' => ['type' => 'string', 'format' => 'binary', 'maxLength' => 16]], 16];
        yield 'octet stream without a schema' => ['application/octet-stream', [], 64];
    }

    public function aRawMediaTypeBesideJsonIsLeftOut(): void
    {
        $operation = $this->contract(['required' => true, 'content' => [
            'text/plain' => ['schema' => ['type' => 'object']],
            'application/json' => ['schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
        ]], ['204' => ['description' => 'none']])->operation('op');
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);

        foreach (range(1, 20) as $seed) {
            Assert::same($arbitrary->generate(new Random($seed))->value['body']['encoding'] ?? null, 'json');
        }
    }

    public function aRawResponseBesideJsonIsLeftOut(): void
    {
        $operation = $this->contract([], ['200' => ['description' => 'two', 'content' => [
            'text/plain' => ['schema' => ['type' => 'string']],
            'application/json' => ['schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
        ]]], method: 'get')->operation('op');
        $arbitrary = (new ResponseCaseArbitrary())->forOperation($operation, 200);

        foreach (range(1, 20) as $seed) {
            Assert::same($arbitrary->generate(new Random($seed))->value['body']['encoding'] ?? null, 'json');
        }
    }

    /**
     * A malformed text schema beside a JSON media type does not refuse the
     * response: the JSON one is generated, as it was before raw bodies were.
     */
    public function aMalformedRawResponseBesideJsonIsLeftOut(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => [
            'text/plain' => ['schema' => ['x']],
            'application/json' => ['schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
        ]]]);

        Assert::same((new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(1))->value['body']['encoding'] ?? null, 'json');
    }

    public function anOptionalRawBodyMayBeAbsent(): void
    {
        $operation = $this->contract(['content' => ['text/plain' => []]], ['204' => ['description' => 'none']])->operation('op');
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $absent = 0;
        foreach (range(1, 40) as $seed) {
            $absent += $arbitrary->generate(new Random($seed))->value['body'] === null ? 1 : 0;
        }

        Assert::true($absent > 0 && $absent < 40);
    }

    public function aRawBodyWithANonStringSchemaIsRefusedByName(): void
    {
        $operation = $this->contract(['required' => true, 'content' => ['application/octet-stream' => ['schema' => ['type' => 'integer']]]], ['204' => ['description' => 'none']])->operation('op');

        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation for operation "op", request body "application/octet-stream": a "application/octet-stream" body is sent as it is, so its schema must be a string schema');

        (new RequestCaseArbitrary())->forOperation($operation);
    }

    /**
     * @param array<string, mixed> $requestBody
     * @param array<string, mixed> $responses
     */
    private function contract(array $requestBody, array $responses, string $method = 'post'): Contract
    {
        $operation = ['operationId' => 'op', 'responses' => $responses];
        if ($requestBody !== []) {
            $operation['requestBody'] = $requestBody;
        }

        return Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/op' => [$method => $operation]]]);
    }
}
