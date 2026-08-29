<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
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
        Assert::true($contract->validateRequest($request)->isValid());
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
        Assert::true($contract->validateRequest($request)->isValid());
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
     * @param null|array{mediaType: string, encoding: 'json', value: mixed} $body
     * @return array{operationKey: string, path: array<never, never>, query: array<never, never>, headers: array<never, never>, cookies: array<never, never>, body: null|array{mediaType: string, encoding: 'json', value: mixed}, misuse: null}
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
}
