<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ServerContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RequestMaterializer::class)]
#[Covers(ParameterSerializer::class)]
final class RequestMaterializerTest
{
    public function serializesSupportedLocationsIntoAContractValidRequest(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}/{labels}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'matrix', 'explode' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'labels', 'in' => 'path', 'required' => true, 'style' => 'label', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'tags', 'in' => 'query', 'style' => 'pipeDelimited', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string']]]],
                            ['name' => 'X-Flags', 'in' => 'header', 'style' => 'simple', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'session', 'in' => 'cookie', 'style' => 'form', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.get'), [
            'operationKey' => 'pets.get',
            'path' => ['id' => '42', 'labels' => ['small', 'friendly']],
            'query' => ['tags' => ['small', 'friendly'], 'filter' => ['state' => 'active']],
            'headers' => ['X-Flags' => ['a', 'b']],
            'cookies' => ['session' => 'abc'],
            'body' => null,
            'misuse' => null,
        ]);

        Assert::same($request->getUri()->getPath(), '/pets/;id=42/.small.friendly');
        Assert::same($request->getUri()->getQuery(), 'tags=small%7Cfriendly&filter%5Bstate%5D=active');
        Assert::same($request->getHeaderLine('X-Flags'), 'a,b');
        Assert::same($request->getHeaderLine('Cookie'), 'session=abc');
        Assert::true($request->getBody()->isReadable());
    }

    public function acceptsAnEmptyDeepObject(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [[
                    'name' => 'filter',
                    'in' => 'query',
                    'style' => 'deepObject',
                    'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string']]],
                ]],
                'responses' => ['200' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.list'), [
            'operationKey' => 'pets.list',
            'path' => [],
            'query' => ['filter' => []],
            'headers' => [],
            'cookies' => [],
            'body' => null,
            'misuse' => null,
        ]);

        Assert::same($request->getUri()->getQuery(), '');
        Assert::true($request->getBody()->isReadable());
    }

    public function keepsReservedPathSlashInsideTemplateSlot(): void
    {
        $operation = new Operation(
            key: 'path.reserved',
            operationId: 'path.reserved',
            method: 'GET',
            path: '/items/{id}',
            parameters: [[
                'name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'simple',
                'explode' => false, 'allowReserved' => true, 'schema' => ['type' => 'string'],
            ]],
        );
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, [
            'operationKey' => 'path.reserved', 'path' => ['id' => 'a/b'], 'query' => [],
            'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null,
        ]);

        Assert::same($request->getUri()->getPath(), '/items/a%2Fb');
    }

    public function preservesNestedJsonObjectsAndArrays(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['post' => [
                'operationId' => 'items.create',
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['items', 'metadata'],
                        'properties' => [
                            'items' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'required' => ['name'],
                                'properties' => ['name' => ['type' => 'string']],
                            ]],
                            'metadata' => ['type' => 'object'],
                        ],
                    ]]],
                ],
                'responses' => ['204' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('items.create'), [
            'operationKey' => 'items.create',
            'path' => [],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => [
                'mediaType' => 'application/json',
                'encoding' => 'json',
                'value' => ['items' => [['name' => 'first'], ['name' => 'second']], 'metadata' => []],
            ],
            'misuse' => null,
        ]);

        Assert::same((string) $request->getBody(), '{"items":[{"name":"first"},{"name":"second"}],"metadata":{}}');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/json');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function convertsStructuralArrayAndObjectSchemasToJsonObjects(): void
    {
        $operation = $this->bodyOperation([
            'content' => ['application/json' => ['schema' => [
                'properties' => [
                    'items' => ['items' => ['properties' => ['name' => ['type' => 'string']]]],
                ],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $this->bodyCase('body.test', [
            'mediaType' => 'application/json',
            'encoding' => 'json',
            'value' => ['items' => [['name' => 'first']]],
        ]));

        Assert::same((string) $request->getBody(), '{"items":[{"name":"first"}]}');

        $emptyNested = $operation;
        $request = (new RequestMaterializer($factory, $factory))->materialize($emptyNested, $this->bodyCase('body.test', [
            'mediaType' => 'application/json',
            'encoding' => 'json',
            'value' => ['items' => [[]]],
        ]));

        Assert::same((string) $request->getBody(), '{"items":[{}]}');
    }

    public function rejectsCaseForAnotherOperation(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize($this->bodyOperation([]), $this->bodyCase('other', null));
    }

    public function rejectsMissingBodyContentDefinition(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => 'invalid']),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 'value']),
        );
    }

    public function rejectsUndeclaredBodyMediaType(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['type' => 'string']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/problem+json', 'encoding' => 'json', 'value' => 'value']),
        );
    }

    public function rejectsListBodySchema(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['invalid']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 'value']),
        );
    }

    public function appliesCredentialsToABodylessRequest(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', null),
            new Credentials(headers: ['X-Api-Key' => ['token']]),
        );

        Assert::same($request->getHeaderLine('X-Api-Key'), 'token');
    }

    public function appliesCredentialsToARequestWithABody(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['type' => 'object']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['a' => 'x']]),
            new Credentials(headers: ['X-Api-Key' => ['token']]),
        );

        Assert::same($request->getHeaderLine('X-Api-Key'), 'token');
        Assert::same((string) $request->getBody(), '{"a":"x"}');
    }

    public function keepsReservedCharactersForAnAllowReservedQueryParameter(): void
    {
        $operation = new Operation(
            key: 'query.reserved',
            operationId: 'query.reserved',
            method: 'GET',
            path: '/items',
            parameters: [[
                'name' => 'q', 'in' => 'query', 'required' => false, 'style' => 'form',
                'explode' => true, 'allowReserved' => true, 'schema' => ['type' => 'string'],
            ]],
        );
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, [
            'operationKey' => 'query.reserved', 'path' => [], 'query' => ['q' => 'a/b:c'],
            'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null,
        ]);

        Assert::same($request->getUri()->getQuery(), 'q=a/b:c');
    }

    public function reportsMissingBodyContentWithAnExactMessage(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Request body content must be an object');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => 'invalid']),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 'value']),
        );
    }

    public function reportsUndeclaredMediaTypeWithAnExactMessage(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Request body media type "application/problem+json" is not declared');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['type' => 'string']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/problem+json', 'encoding' => 'json', 'value' => 'value']),
        );
    }

    public function prefersTheDeclaredDefinitionOverTheJsonFallbackForAMediaTypeMisuse(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => [
                'text/plain' => ['schema' => ['type' => 'object']],
                'application/json' => ['schema' => ['poison']],
            ]]),
            $this->misuseBodyCase('media-type', ['mediaType' => 'text/plain', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );

        Assert::same((string) $request->getBody(), '{"a":"x"}');
        Assert::same($request->getHeaderLine('Content-Type'), 'text/plain');
    }

    public function usesTheJsonFallbackOnlyForAMediaTypeMisuseKind(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => [
                'text/plain' => ['schema' => ['type' => 'object']],
                'application/json' => ['schema' => ['poison']],
            ]]),
            $this->misuseBodyCase('type', ['mediaType' => 'text/plain', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );

        Assert::same((string) $request->getBody(), '{"a":"x"}');
    }

    public function fallbackMatchesAJsonMediaTypeCaseInsensitivelyWithParameters(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['Application/JSON ; charset=utf-8' => ['schema' => ['type' => 'object']]]]),
            $this->misuseBodyCase('media-type', ['mediaType' => 'application/xml', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );

        Assert::same((string) $request->getBody(), '{"a":"x"}');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/xml');
    }

    public function fallbackSkipsANonJsonDefinitionBeforeTheJsonOne(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => [
                'text/plain' => ['schema' => ['poison']],
                'application/json' => ['schema' => ['type' => 'object']],
            ]]),
            $this->misuseBodyCase('media-type', ['mediaType' => 'application/xml', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );

        Assert::same((string) $request->getBody(), '{"a":"x"}');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/xml');
    }

    public function fallbackSkipsANonArrayJsonDefinition(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Request body media type "application/xml" is not declared');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => 'garbage']]),
            $this->misuseBodyCase('media-type', ['mediaType' => 'application/xml', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );
    }

    public function fallbackContinuesPastANonArrayDefinitionToTheJsonOne(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => [
                'text/csv' => 'garbage',
                'application/json' => ['schema' => ['type' => 'object']],
            ]]),
            $this->misuseBodyCase('media-type', ['mediaType' => 'application/xml', 'encoding' => 'json', 'value' => ['a' => 'x']]),
        );

        Assert::same((string) $request->getBody(), '{"a":"x"}');
    }

    public function encodesAListValueUnderAnObjectSchemaAsAJsonArray(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['type' => 'object']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['a', 'b']]),
        );

        Assert::same((string) $request->getBody(), '["a","b"]');
    }

    public function keepsAnEmptyArrayUnderANonObjectSchemaAsAJsonArray(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation(['content' => ['application/json' => ['schema' => ['type' => 'string']]]]),
            $this->bodyCase('body.test', ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => []]),
        );

        Assert::same((string) $request->getBody(), '[]');
    }

    public function serializesFormUrlencodedBodiesUsingEncodingRules(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/form' => ['post' => [
                'operationId' => 'form.create',
                'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => [
                    'schema' => ['type' => 'object', 'required' => ['name', 'tags', 'filter'], 'properties' => [
                        'name' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'filter' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string']]],
                    ]],
                    'encoding' => ['tags' => ['style' => 'form', 'explode' => false]],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('form.create'), $this->bodyCase('form.create', [
            'mediaType' => 'application/x-www-form-urlencoded', 'encoding' => 'form',
            'value' => ['name' => 'Jane Doe', 'tags' => ['one', 'two'], 'filter' => ['state' => 'active']],
        ]));

        Assert::same((string) $request->getBody(), 'name=Jane%20Doe&tags=one,two&state=active');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
        Assert::true($request->getBody()->isReadable());
    }

    public function materializesMultipartTextArraysHeadersAndBinaryCorpusValues(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/upload' => ['post' => [
                'operationId' => 'upload.create',
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'required' => ['title', 'tags', 'file'], 'properties' => [
                        'title' => ['type' => 'string'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'file' => ['type' => 'string', 'format' => 'binary'],
                    ]],
                    'encoding' => ['title' => ['headers' => ['X-Part' => ['required' => true]]]],
                ]]],
                'responses' => ['201' => []],
            ]]],
        ]);
        $boundary = 'openapi-test-boundary';
        $case = $this->bodyCase('upload.create', [
            'mediaType' => 'multipart/form-data', 'encoding' => 'multipart', 'boundary' => $boundary,
            'parts' => [
                ['name' => 'title', 'value' => 'hello', 'encoding' => 'text', 'contentType' => 'text/plain', 'headers' => ['X-Part' => 'yes']],
                ['name' => 'tags', 'value' => 'one', 'encoding' => 'text', 'contentType' => 'text/plain', 'headers' => []],
                ['name' => 'tags', 'value' => 'two', 'encoding' => 'text', 'contentType' => 'text/plain', 'headers' => []],
                ['name' => 'file', 'value' => base64_encode("\x00\xFF"), 'encoding' => 'base64', 'contentType' => 'application/octet-stream', 'headers' => []],
            ],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('upload.create'), $case);

        Assert::same(
            (string) $request->getBody(),
            '--openapi-test-boundary' . "\r\n"
            . 'Content-Disposition: form-data; name="title"' . "\r\n"
            . 'Content-Type: text/plain' . "\r\n"
            . 'X-Part: yes' . "\r\n\r\n"
            . 'hello' . "\r\n"
            . '--openapi-test-boundary' . "\r\n"
            . 'Content-Disposition: form-data; name="tags"' . "\r\n"
            . 'Content-Type: text/plain' . "\r\n\r\n"
            . 'one' . "\r\n"
            . '--openapi-test-boundary' . "\r\n"
            . 'Content-Disposition: form-data; name="tags"' . "\r\n"
            . 'Content-Type: text/plain' . "\r\n\r\n"
            . 'two' . "\r\n"
            . '--openapi-test-boundary' . "\r\n"
            . 'Content-Disposition: form-data; name="file"' . "\r\n"
            . 'Content-Type: application/octet-stream' . "\r\n\r\n"
            . "\x00\xFF\r\n"
            . '--openapi-test-boundary--' . "\r\n",
        );
        Assert::same($request->getHeaderLine('Content-Type'), 'multipart/form-data; boundary=' . $boundary);
        Assert::true($request->getBody()->isReadable());
    }

    public function escapesMultipartPartNamesWithoutAllowingHeaderInjection(): void
    {
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', [
                'mediaType' => 'multipart/form-data',
                'encoding' => 'multipart',
                'boundary' => 'boundary',
                'parts' => [[
                    'name' => "field\\\"\r\nInjected",
                    'value' => 'value',
                    'encoding' => 'text',
                    'contentType' => 'text/plain',
                    'headers' => [],
                ]],
            ]),
        );

        Assert::same(
            (string) $request->getBody(),
            '--boundary' . "\r\n"
            . 'Content-Disposition: form-data; name="field\\\\\"Injected"' . "\r\n"
            . 'Content-Type: text/plain' . "\r\n\r\n"
            . 'value' . "\r\n"
            . '--boundary--' . "\r\n",
        );
    }

    public function rejectsMultipartWithoutParts(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Multipart request body has an invalid shape');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', [
                'mediaType' => 'multipart/form-data',
                'encoding' => 'multipart',
                'boundary' => 'boundary',
            ]),
        );
    }

    public function rejectsMultipartWithoutBoundary(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Multipart request body has an invalid shape');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', [
                'mediaType' => 'multipart/form-data',
                'encoding' => 'multipart',
                'parts' => [],
            ]),
        );
    }

    #[DataProvider('invalidMultipartBoundaryProvider')]
    public function rejectsInvalidMultipartBoundary(string $boundary): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Multipart boundary is invalid');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', [
                'mediaType' => 'multipart/form-data',
                'encoding' => 'multipart',
                'boundary' => $boundary,
                'parts' => [],
            ]),
        );
    }

    public static function invalidMultipartBoundaryProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'over 70 characters' => [str_repeat('a', 71)];
        yield 'invalid prefix' => ["\nboundary"];
        yield 'invalid suffix' => ["boundary\n"];
    }

    public function rejectsInvalidMultipartBase64Value(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Multipart base64 value is invalid');

        $factory = new Psr17Factory();
        (new RequestMaterializer($factory, $factory))->materialize(
            $this->bodyOperation([]),
            $this->bodyCase('body.test', [
                'mediaType' => 'multipart/form-data',
                'encoding' => 'multipart',
                'boundary' => 'boundary',
                'parts' => [[
                    'name' => 'file',
                    'value' => 'not-base64!',
                    'encoding' => 'base64',
                    'contentType' => 'application/octet-stream',
                    'headers' => [],
                ]],
            ]),
        );
    }

    /** @param array<array-key, mixed> $requestBody */
    private function bodyOperation(array $requestBody): Operation
    {
        return new Operation(
            key: 'body.test',
            operationId: 'body.test',
            method: 'POST',
            path: '/body',
            requestBody: $requestBody,
        );
    }

    /**
     * @param null|array<string, mixed> $body
     * @return array{operationKey: string, path: array<never, never>, query: array<never, never>, headers: array<never, never>, cookies: array<never, never>, body: null|array<string, mixed>, misuse: null}
     */
    private function bodyCase(string $operationKey, ?array $body): array
    {
        return [
            'operationKey' => $operationKey,
            'path' => [],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => $body,
            'misuse' => null,
        ];
    }

    /**
     * @param non-empty-string $kind
     * @param array{mediaType: string, encoding: 'json', value: mixed} $body
     * @return array{operationKey: string, path: array<never, never>, query: array<never, never>, headers: array<never, never>, cookies: array<never, never>, body: array{mediaType: string, encoding: 'json', value: mixed}, misuse: array{kind: non-empty-string, location: non-empty-string, name: string}}
     */
    private function misuseBodyCase(string $kind, array $body): array
    {
        return [
            'operationKey' => 'body.test',
            'path' => [],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => $body,
            'misuse' => ['kind' => $kind, 'location' => 'body', 'name' => 'body'],
        ];
    }


    public function prefixesTheDeclaredRelativeServerBase(): void
    {
        $contract = $this->serverContract(['/api/v1']);
        $request = $this->materializer()->materialize($contract->operation('pets.get'), $this->petCase());

        Assert::same((string) $request->getUri(), '/api/v1/pets/42');
        Assert::same($request->getUri()->getHost(), '');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function buildsAnAbsoluteUriFromAnAbsoluteServerWithSubstitutedVariables(): void
    {
        $contract = $this->serverContract([
            ['url' => 'https://{env}.example.com:8443/v{version}', 'variables' => ['env' => ['default' => 'api', 'enum' => ['api', 'staging']], 'version' => ['default' => '2']]],
            'https://other.example.com/v2',
        ]);
        $request = $this->materializer()->materialize($contract->operation('pets.get'), $this->petCase());

        Assert::same((string) $request->getUri(), 'https://api.example.com:8443/v2/pets/42');
        Assert::same($contract->match($request)?->operation->key, 'pets.get');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function honorsOperationLevelServerPrecedence(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://root.example.com/root']],
            'paths' => [
                '/pets/{id}' => [
                    'servers' => [['url' => 'https://path.example.com/path']],
                    'get' => [
                        'operationId' => 'pets.get',
                        'servers' => [['url' => 'https://operation.example.com/operation']],
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'responses' => ['200' => []],
                    ],
                    'delete' => [
                        'operationId' => 'pets.delete',
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'responses' => ['204' => []],
                    ],
                ],
            ],
        ]);

        $get = $this->materializer()->materialize($contract->operation('pets.get'), $this->petCase());
        $delete = $this->materializer()->materialize($contract->operation('pets.delete'), ['operationKey' => 'pets.delete'] + $this->petCase());

        Assert::same((string) $get->getUri(), 'https://operation.example.com/operation/pets/42');
        Assert::same((string) $delete->getUri(), 'https://path.example.com/path/pets/42');
        Assert::true($contract->validateRequest($get)->isValid());
        Assert::true($contract->validateRequest($delete)->isValid());
    }

    public function fallsBackToTheBasePathProjectionOfAHandBuiltOperation(): void
    {
        $operation = new Operation(key: 'legacy.get', operationId: 'legacy.get', method: 'GET', path: '/pets', serverBases: ['/legacy']);
        $request = $this->materializer()->materialize($operation, ['operationKey' => 'legacy.get', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null]);

        Assert::same((string) $request->getUri(), '/legacy/pets');
    }

    #[DataProvider('baseUriProvider')]
    public function replacesTheDeclaredServerWithTheBaseUri(string $baseUri, string $expected): void
    {
        $contract = $this->serverContract(['https://api.example.com/v1']);
        $request = $this->materializer()->withBaseUri($baseUri)->materialize($contract->operation('pets.get'), $this->petCase());

        Assert::same((string) $request->getUri(), $expected);
    }

    public static function baseUriProvider(): iterable
    {
        yield 'root-relative base' => ['/local', '/local/pets/42'];
        yield 'root' => ['/', '/pets/42'];
        yield 'absolute host' => ['http://localhost:8080', 'http://localhost:8080/pets/42'];
        yield 'absolute host with base' => ['http://localhost:8080/v1', 'http://localhost:8080/v1/pets/42'];
    }

    #[DataProvider('malformedBaseUriProvider')]
    public function rejectsAMalformedBaseUri(string $baseUri): void
    {
        Expect::exception(\InvalidArgumentException::class);

        $this->materializer()->withBaseUri($baseUri);
    }

    public static function malformedBaseUriProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'trailing slash' => ['/local/'];
        yield 'protocol-relative' => ['//localhost'];
        yield 'query' => ['http://localhost/?debug'];
        yield 'fragment' => ['http://localhost/#top'];
        yield 'userinfo' => ['http://user@localhost'];
        yield 'unsupported scheme' => ['ftp://localhost'];
        yield 'bare authority' => ['localhost:8080'];
        yield 'whitespace' => ['http://localhost /v1'];
    }

    #[Property(runs: 60, generators: [ServerContracts::class, 'multiHostCase'])]
    public function generatedCasesMatchTheirOperationUnderMultiHostServers(array $case): void
    {
        $contract = ServerContracts::multiHost();
        /** @var array{operationKey: string, path: array<string, string|list<string>|array<string, string>>, query: array<string, string|list<string>|array<string, string>>, headers: array<string, string|list<string>|array<string, string>>, cookies: array<string, string|list<string>|array<string, string>>, body: null|array{mediaType: string, encoding: 'json', value: mixed}, misuse: null} $case */
        $request = $this->materializer()->materialize($contract->operation('pets.get'), $case);

        Assert::same($request->getUri()->getHost(), 'a.example.com');
        Assert::same($contract->match($request)?->operation->key, 'pets.get');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    /** @param list<array<string, mixed>|string> $servers */
    private function serverContract(array $servers): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => array_map(static fn(array|string $server): array => is_string($server) ? ['url' => $server] : $server, $servers),
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
    }

    /** @return array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: null, misuse: null} */
    private function petCase(): array
    {
        return ['operationKey' => 'pets.get', 'path' => ['id' => '42'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null];
    }

    private function materializer(): RequestMaterializer
    {
        $factory = new Psr17Factory();

        return new RequestMaterializer($factory, $factory);
    }
}
