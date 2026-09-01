<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\DocumentExamples;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(DocumentExamples::class)]
final class DocumentExamplesTest
{
    public function producesNoCasesWithoutExamples(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => [
                'operationId' => 'pets.get',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => ['200' => []],
            ]]],
        ]);

        Assert::same((new DocumentExamples())->forOperation($contract->operation('pets.get')), []);
    }

    public function parameterExampleWinsOverSchemaExampleWhichWinsOverSchemaExamples(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [
                    ['name' => 'a', 'in' => 'query', 'required' => true, 'example' => 1, 'schema' => ['type' => 'integer', 'example' => 2, 'examples' => [3]]],
                    ['name' => 'b', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'example' => 2, 'examples' => [3]]],
                    ['name' => 'c', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'examples' => [3, 4]]],
                    ['name' => 'd', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 5]],
                ],
                'responses' => ['200' => []],
            ]]],
        ]);

        $cases = (new DocumentExamples())->forOperation($contract->operation('pets.list'));

        Assert::same(array_keys($cases), ['example']);
        Assert::same($cases['example']['query'], ['a' => '1', 'b' => '2', 'c' => '3', 'd' => '5']);
        Assert::same($cases['example']['operationKey'], 'pets.list');
        Assert::same($cases['example']['misuse'], null);
    }

    public function producesOneCasePerExampleNameAcrossAllParts(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['post' => [
                'operationId' => 'pets.update',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1], 'examples' => ['minimal' => ['value' => 1], 'full' => ['value' => 1]]],
                    ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'example' => false, 'examples' => ['full' => ['summary' => 'everything', 'value' => true]]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'const' => 'base']]],
                    'examples' => ['minimal' => ['value' => ['name' => 'base']], 'external' => ['externalValue' => 'https://example.com/e.json']],
                ]]],
                'responses' => ['200' => []],
            ]]],
        ]);

        $cases = (new DocumentExamples())->forOperation($contract->operation('pets.update'));

        Assert::same(array_keys($cases), ['example', 'minimal', 'full']);
        Assert::same($cases['example']['query'], ['verbose' => 'false']);
        Assert::same($cases['minimal']['query'], ['verbose' => 'false']);
        Assert::same($cases['full']['query'], ['verbose' => 'true']);
        Assert::same($cases['minimal']['body'], ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['name' => 'base']]);
        Assert::same($cases['full']['body'], ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['name' => 'base']]);
        foreach ($cases as $case) {
            Assert::same($case['path'], ['id' => '1']);
        }
    }

    public function convertsExampleValuesToTheWireShape(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [
                    ['name' => 'ratio', 'in' => 'query', 'example' => 1.5, 'schema' => ['type' => 'number']],
                    ['name' => 'flag', 'in' => 'query', 'example' => true, 'schema' => ['type' => 'boolean']],
                    ['name' => 'nothing', 'in' => 'query', 'example' => null, 'schema' => ['type' => 'null']],
                    ['name' => 'tags', 'in' => 'query', 'example' => ['a', 2], 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ['name' => 'filter', 'in' => 'query', 'example' => ['state' => 'active', 'age' => 3], 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string'], 'age' => ['type' => 'integer']]]],
                ],
                'responses' => ['200' => []],
            ]]],
        ]);

        $case = (new DocumentExamples())->forOperation($contract->operation('pets.list'))['example'];
        $query = $case['query'];
        ksort($query);

        Assert::same($query, ['filter' => ['state' => 'active', 'age' => '3'], 'flag' => 'true', 'nothing' => 'null', 'ratio' => '1.5', 'tags' => ['a', '2']]);
    }

    public function formBodyExamplesKeepTheFormEncoding(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/login' => ['post' => [
                'operationId' => 'login',
                'requestBody' => ['content' => ['application/x-www-form-urlencoded' => [
                    'schema' => ['type' => 'object', 'properties' => ['user' => ['type' => 'string']], 'example' => ['user' => 'alice']],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $case = (new DocumentExamples())->forOperation($contract->operation('login'))['example'];

        Assert::same($case['body'], ['mediaType' => 'application/x-www-form-urlencoded', 'encoding' => 'form', 'value' => ['user' => 'alice']]);
    }

    public function multipartBodiesContributeNoExamples(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/upload' => ['post' => [
                'operationId' => 'upload',
                'requestBody' => ['content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]],
                    'example' => ['file' => 'raw'],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Assert::same((new DocumentExamples())->forOperation($contract->operation('upload')), []);
    }

    public function everyExampleCaseOfAConformingDocumentValidates(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.example.com/v1']],
            'paths' => ['/pets/{id}' => ['put' => [
                'operationId' => 'pets.put',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1], 'examples' => ['first' => ['value' => 1], 'big' => ['value' => 999]]],
                    ['name' => 'X-Trace', 'in' => 'header', 'schema' => ['type' => 'string', 'example' => 'abc']],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => [
                    'schema' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'minLength' => 1], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                    'example' => ['name' => 'Rex', 'tags' => ['good']],
                ]]],
                'responses' => ['200' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $operation = $contract->operation('pets.put');

        $cases = (new DocumentExamples())->forOperation($operation);

        Assert::same(array_keys($cases), ['example', 'first', 'big']);
        foreach ($cases as $case) {
            Assert::true($contract->validateRequest($materializer->materialize($operation, $case))->isValid());
        }
    }

    #[DataProvider('unsupportedProvider')]
    public function failsClosedOnExamplesItCannotRepresent(array $parameter, string $message): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => ['operationId' => 'pets.list', 'parameters' => [$parameter], 'responses' => ['200' => []]]]],
        ]);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new DocumentExamples())->forOperation($contract->operation('pets.list'));
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'nested object' => [
            ['name' => 'filter', 'in' => 'query', 'example' => ['a' => ['b' => 1]], 'schema' => ['type' => 'object']],
            'Example of parameter "filter" must be a scalar, a list of scalars, or a map of scalars',
        ];
        yield 'map for an array schema' => [
            ['name' => 'tags', 'in' => 'query', 'example' => ['x' => 'y'], 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'Example of parameter "tags" must be a list for an array schema',
        ];
        yield 'example object that is not an object' => [
            ['name' => 'id', 'in' => 'query', 'examples' => ['odd' => 'plain'], 'schema' => ['type' => 'string']],
            'Example "odd" of parameter "id" must be an Example Object',
        ];
    }

    public function rejectsAListOfBodyExamples(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['post' => [
                'operationId' => 'pets.create',
                'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object'], 'examples' => [['value' => []]]]]],
                'responses' => ['201' => []],
            ]]],
        ]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Examples of request body "application/json" must be a map of Example Objects');

        (new DocumentExamples())->forOperation($contract->operation('pets.create'));
    }
}
