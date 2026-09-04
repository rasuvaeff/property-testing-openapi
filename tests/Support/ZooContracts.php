<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\Random;

/**
 * The "zoo": one operation per schema feature the valid phase has to get
 * right end to end (materialize → validate → transport → validate the
 * exchange), shared between property bodies and their generator providers.
 */
final class ZooContracts
{
    /** @var list<string> */
    public const array VALID_OPERATIONS = [
        'strings.get', 'enum.get', 'users.create', 'merged.create', 'extras.create',
        'nested.create', 'health.get', 'version.get', 'files.get',
        'search.get', 'narrowed.create',
    ];

    /**
     * The operations declared by the OAS 3.0 document, where `nullable` is a
     * keyword rather than a stray annotation.
     *
     * @var list<string>
     */
    public const array LEGACY_OPERATIONS = ['search.get', 'narrowed.create'];

    public static function contract(): Contract
    {
        $user = [
            'type' => 'object',
            'required' => ['id', 'name', 'profile'],
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                'profile' => [
                    'type' => 'object',
                    'required' => ['slug', 'createdAt'],
                    'additionalProperties' => false,
                    'properties' => [
                        'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6],
                        'createdAt' => ['type' => 'string', 'format' => 'date-time', 'readOnly' => true],
                    ],
                ],
                'history' => ['type' => 'array', 'maxItems' => 2, 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'at' => ['type' => 'string', 'readOnly' => true],
                        'note' => ['type' => 'string', 'maxLength' => 4],
                    ],
                ]],
            ],
            'example' => ['id' => 7, 'name' => 'Ann', 'profile' => ['slug' => 'ann', 'createdAt' => '2024-01-01T00:00:00Z'], 'history' => [['at' => 'x', 'note' => 'hi']]],
        ];

        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/strings/{key}/tags/{tag}' => ['get' => [
                    'operationId' => 'strings.get',
                    'parameters' => [
                        ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                        ['name' => 'tag', 'in' => 'path', 'required' => true, 'style' => 'label', 'schema' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'maxLength' => 4]]],
                        ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 3]],
                    ],
                    'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]],
                ]],
                '/enum/{mode}' => ['get' => [
                    'operationId' => 'enum.get',
                    'parameters' => [['name' => 'mode', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['', 'a/b', 'ok', 'x\\y']]]],
                    'responses' => ['204' => []],
                ]],
                '/users' => ['post' => [
                    'operationId' => 'users.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $user]]],
                    'responses' => ['201' => ['content' => ['application/json' => ['schema' => $user]]]],
                ]],
                '/merged' => ['post' => [
                    'operationId' => 'merged.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['allOf' => [
                        ['type' => 'object', 'required' => ['a'], 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string', 'maxLength' => 3], 'b' => ['type' => 'integer']]],
                        ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'minLength' => 1]], 'required' => ['b']],
                    ]]]]],
                    'responses' => ['204' => []],
                ]],
                '/conflict' => ['post' => [
                    'operationId' => 'conflict.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['allOf' => [
                        ['type' => 'object', 'additionalProperties' => false, 'properties' => ['a' => ['type' => 'string']]],
                        ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
                    ]]]]],
                    'responses' => ['204' => []],
                ]],
                '/extras' => ['post' => [
                    'operationId' => 'extras.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'minProperties' => 1, 'maxProperties' => 3,
                        'additionalProperties' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9],
                    ]]]],
                    'responses' => ['204' => []],
                ]],
                '/nested' => ['post' => [
                    'operationId' => 'nested.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object', 'minProperties' => 1, 'maxProperties' => 2,
                        'additionalProperties' => [
                            'type' => 'object', 'maxProperties' => 1,
                            'properties' => ['n' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
                        ],
                    ]]]],
                    'responses' => ['204' => []],
                ]],
                '/health' => ['get' => [
                    'operationId' => 'health.get',
                    'responses' => ['200' => ['content' => ['text/plain' => []]]],
                ]],
                '/version' => ['get' => [
                    'operationId' => 'version.get',
                    'responses' => ['200' => ['content' => ['text/plain' => ['schema' => ['type' => 'string', 'maxLength' => 5]]]]],
                ]],
                '/files/{id}' => ['get' => [
                    'operationId' => 'files.get',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1]]],
                    'responses' => ['200' => ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]]],
                ]],
                '/uuid/{id}' => ['get' => [
                    'operationId' => 'uuid.get',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid', 'maxLength' => 10]]],
                    'responses' => ['204' => []],
                ]],
                '/links/{href}' => ['get' => [
                    'operationId' => 'links.get',
                    'parameters' => [['name' => 'href', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uri']]],
                    'responses' => ['204' => []],
                ]],
            ],
        ]);
    }

    /**
     * OpenAPI 3.0 spelling of nullability, which a parameter cannot carry.
     */
    public static function legacy(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.0.3',
            'paths' => ['/search/{scope}' => ['get' => [
                'operationId' => 'search.get',
                'parameters' => [
                    ['name' => 'scope', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'nullable' => true, 'enum' => ['all', 'mine', null]]],
                    ['name' => 'limit', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer', 'nullable' => true, 'minimum' => 1, 'maximum' => 5]],
                    ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'nullable' => true, 'maxLength' => 4]],
                    ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['age' => ['type' => 'integer', 'nullable' => true, 'minimum' => 0]]]],
                ],
                'responses' => ['204' => []],
            ]],
                '/narrowed' => ['post' => [
                    'operationId' => 'narrowed.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['allOf' => [
                        ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'nullable' => true, 'maxLength' => 6]]],
                        ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'minLength' => 1]]],
                    ]]]]],
                    'responses' => ['204' => []],
                ]]],
        ]);
    }

    /**
     * The suite that declares the operation, so callers do not repeat the
     * 3.0/3.1 split.
     */
    public static function suiteFor(string $key): ContractSuite
    {
        return in_array($key, self::LEGACY_OPERATIONS, strict: true) ? self::legacySuite() : self::suite();
    }

    public static function suite(): ContractSuite
    {
        $factory = new Psr17Factory();

        return ContractSuite::fromContract(self::contract(), $factory, $factory)
            ->operations(['strings.get', 'enum.get', 'users.create', 'merged.create', 'extras.create', 'nested.create', 'health.get', 'version.get', 'files.get'])
            ->allowUnsafeOperations()
            ->transport(self::transport());
    }

    public static function legacySuite(): ContractSuite
    {
        $factory = new Psr17Factory();

        return ContractSuite::fromContract(self::legacy(), $factory, $factory)
            ->operations(self::LEGACY_OPERATIONS)
            ->allowUnsafeOperations()
            ->transport(self::transport());
    }

    /**
     * Answers every zoo operation with a response its document declares.
     */
    public static function transport(): CallableTransport
    {
        return new CallableTransport(static function (RequestInterface $request): ResponseInterface {
            $path = $request->getUri()->getPath();

            return match (true) {
                str_starts_with($path, '/strings/') => new Response(200, ['Content-Type' => 'application/json'], '{}'),
                $path === '/users' => new Response(201, ['Content-Type' => 'application/json'], '{"id":1,"name":"Ann","profile":{"slug":"ann","createdAt":"2024-01-01T00:00:00Z"}}'),
                $path === '/health' => new Response(200, ['Content-Type' => 'text/plain'], 'ok'),
                $path === '/version' => new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], 'v1'),
                str_starts_with($path, '/files/') => new Response(200, ['Content-Type' => 'application/octet-stream'], "\x00\x01"),
                default => new Response(204),
            };
        });
    }

    /**
     * One fixed-seed valid case per zoo operation, tagged with its key.
     *
     * @return array<string, array{key: string, case: array<string, mixed>}>
     */
    public static function taggedExamples(): array
    {
        $examples = [];
        foreach (self::VALID_OPERATIONS as $key) {
            $cases = self::suiteFor($key)->validCases($key);
            $examples[$key] = ['key' => $key, 'case' => $cases->generate(new Random(7))->value];
        }

        return $examples;
    }

    /**
     * A valid case of one of the zoo operations, tagged with its key.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function taggedCase(): array
    {
        return ['tagged' => Gen::flatMap(Gen::elements(self::VALID_OPERATIONS), static function (mixed $key): ArbitraryInterface {
            \assert(is_string($key));

            return Gen::map(self::suiteFor($key)->validCases($key), static fn(array $case): array => ['key' => $key, 'case' => $case]);
        })];
    }
}
