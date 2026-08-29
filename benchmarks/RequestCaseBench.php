<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Benchmarks;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Bench;

final class RequestCaseBench
{
    private const array OBJECT_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'name'],
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 32],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 8], 'maxItems' => 4],
        ],
    ];

    private static ?Operation $operation = null;

    private static ?SchemaArbitraryCompiler $compiler = null;

    private static ?ArbitraryInterface $caseArbitrary = null;

    private static ?RequestMaterializer $materializer = null;

    #[Bench(
        callables: ['fresh compiler per call' => [self::class, 'compileWithFreshCompiler']],
        calls: 2_000,
        iterations: 10,
    )]
    public static function compileObjectSchema(): ArbitraryInterface
    {
        return (self::$compiler ??= new SchemaArbitraryCompiler())->compile(self::OBJECT_SCHEMA);
    }

    #[Bench(
        callables: ['rebuild arbitrary per case' => [self::class, 'generateWithFreshArbitrary']],
        calls: 500,
        iterations: 10,
    )]
    public static function generateValidCase(): array
    {
        $arbitrary = self::$caseArbitrary ??= (new RequestCaseArbitrary())->forOperation(self::operation());

        return $arbitrary->generate(new Random(42))->value;
    }

    #[Bench(
        callables: ['fresh materializer per call' => [self::class, 'materializeWithFreshMaterializer']],
        calls: 500,
        iterations: 10,
    )]
    public static function generateAndMaterializeRequest(): RequestInterface
    {
        if (!self::$materializer instanceof RequestMaterializer) {
            $factory = new Psr17Factory();
            self::$materializer = new RequestMaterializer($factory, $factory);
        }

        return self::$materializer->materialize(self::operation(), self::generateValidCase());
    }

    public static function compileWithFreshCompiler(): ArbitraryInterface
    {
        return (new SchemaArbitraryCompiler())->compile(self::OBJECT_SCHEMA);
    }

    public static function materializeWithFreshMaterializer(): RequestInterface
    {
        $factory = new Psr17Factory();

        return (new RequestMaterializer($factory, $factory))->materialize(self::operation(), self::generateValidCase());
    }

    public static function generateWithFreshArbitrary(): array
    {
        return (new RequestCaseArbitrary())->forOperation(self::operation())->generate(new Random(42))->value;
    }

    private static function operation(): Operation
    {
        return self::$operation ??= Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items/{id}' => ['post' => [
                'operationId' => 'items.update',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]],
                    ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => self::OBJECT_SCHEMA]]],
                'responses' => ['204' => []],
            ]]],
        ])->operation('items.update');
    }
}
