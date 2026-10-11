<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ResponseContracts;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ResponseMaterializer::class)]
#[Covers(JsonBodyEncoder::class)]
final class ResponseMaterializerTest
{
    public function materializesStatusHeadersAndASchemaAwareJsonBody(): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'pets.get',
            'status' => 200,
            'headers' => ['X-Rate-Limit' => '5', 'X-Flag' => 'true', 'X-Ids' => ['1', '2'], 'X-Tags' => ['a', 'b']],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['id' => 1, 'name' => 'Rex', 'status' => 'active', 'kind' => 'pet', 'tags' => ['x'], 'owner' => ['name' => 'Ann']]],
            'misuse' => null,
        ]);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('X-Rate-Limit'), '5');
        Assert::same($response->getHeaderLine('X-Tags'), 'a,b');
        Assert::same($response->getHeaderLine('X-Ids'), '1,2');
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $response->getBody(), '{"id":1,"name":"Rex","status":"active","kind":"pet","tags":["x"],"owner":{"name":"Ann"}}');
        Assert::true($response->getBody()->isSeekable());
        Assert::true($contract->validateResponse('pets.get', $response)->isValid());
    }

    public function serializesHeadersWithTheSimpleStyle(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['204' => []]);

        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op',
            'status' => 204,
            'headers' => ['X-Trace' => 'a b, c', 'X-Tags' => ['a', 'c d', 'é'], 'X-Plain' => '42'],
            'body' => null,
            'misuse' => null,
        ]);

        // Written as sent: the comma of a list still separates, and nothing
        // else is escaped (openapi-contract#66). A member that carries the
        // comma has no spelling any more, which is why the generator does not
        // build one.
        Assert::same($response->getHeaderLine('X-Trace'), 'a b, c');
        Assert::same($response->getHeaderLine('X-Tags'), 'a,c d,é');
        Assert::same($response->getHeader('X-Tags'), ['a,c d,é']);
        Assert::same($response->getHeaderLine('X-Plain'), '42');
    }

    /**
     * Percent-encoding used to make this unreachable: a CR or an LF came out
     * as `%0D%0A` and travelled harmlessly. Written as sent, the same case
     * would be a response-splitting payload, so it is refused here — in this
     * package's vocabulary rather than as whatever a PSR-7 implementation
     * happens to throw. No generated case reaches it.
     */
    public function refusesAHeaderValueNoHttpFieldCanCarry(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['204' => []]);

        Expect::exception(InvalidCase::class)->withMessage('Header "X-Trace" carries a value no HTTP field can');

        $this->materializer()->materialize($operation, [
            'operationKey' => 'op',
            'status' => 204,
            'headers' => ['X-Trace' => "a\r\nb"],
            'body' => null,
            'misuse' => null,
        ]);
    }

    public function writesARawBodyVerbatim(): void
    {
        $response = $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => '{"malformed":'],
            'misuse' => ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'],
        ]);

        Assert::same((string) $response->getBody(), '{"malformed":');
    }

    public function rejectsANonStringRawBody(): void
    {
        Expect::exception(InvalidCase::class)->withMessage('Raw response body value must be a string');

        $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => ['x']],
            'misuse' => null,
        ]);
    }

    public function rejectsACaseForAnotherOperation(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Response case targets "pets.list", not "pets.get"');

        $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.list', 'status' => 200, 'headers' => [], 'body' => null, 'misuse' => null,
        ]);
    }

    public function encodesAMediaTypeMisuseWithTheDeclaredSchema(): void
    {
        $response = $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/x-openapi-misuse', 'encoding' => 'json', 'value' => ['id' => 1, 'owner' => []]],
            'misuse' => ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'],
        ]);

        Assert::same($response->getHeaderLine('Content-Type'), 'application/x-openapi-misuse');
        Assert::same((string) $response->getBody(), '{"id":1,"owner":{}}');
    }

    public function encodesAnUndeclaredStatusMisuseWithAnyDeclaredJsonSchema(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: [
            '204' => ['description' => 'none'],
            '200' => ['content' => ['text/html' => ['schema' => ['type' => 'string']], 'Application/VND.X+JSON ; v=1' => ['schema' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]]]],
        ]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 599, 'headers' => [],
            'body' => ['mediaType' => 'Application/VND.X+JSON ; v=1', 'encoding' => 'json', 'value' => ['meta' => []]],
            'misuse' => ['kind' => 'undeclared-status', 'location' => 'status', 'name' => '599'],
        ]);

        Assert::same((string) $response->getBody(), '{"meta":{}}');
    }

    public function encodesWithoutASchemaWhenNoneIsDeclared(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => []]]]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['meta' => []]],
            'misuse' => null,
        ]);

        Assert::same((string) $response->getBody(), '{"meta":[]}');

        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['description' => 'no content']]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 1],
            'misuse' => null,
        ]);

        Assert::same((string) $response->getBody(), '1');
    }

    public function rejectsAListSchemaWhileEncoding(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['a']]]]]);

        Expect::exception(InvalidCase::class)->withMessage('Response JSON schema must be an object');

        $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 1],
            'misuse' => null,
        ]);
    }

    public function jsonEncoderKeepsEmptyObjectsInsideLists(): void
    {
        Assert::same((new JsonBodyEncoder())->encode([[]], ['type' => 'array', 'items' => ['type' => 'object']]), '[{}]');
        Assert::same((new JsonBodyEncoder())->encode([[]], ['items' => ['type' => 'object']]), '[{}]');
        Assert::same((new JsonBodyEncoder())->encode(['a', 'b'], ['type' => 'object']), '["a","b"]');
        Assert::same((new JsonBodyEncoder())->encode(['a' => [], 'b' => []], ['type' => 'object', 'properties' => ['a' => ['type' => 'object'], 'b' => ['type' => 'object']]]), '{"a":{},"b":{}}');
        Assert::same(
            (new JsonBodyEncoder())->encode(['v1', 2], ['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'integer']]]),
            '["v1",2]',
        );
        Assert::same(
            (new JsonBodyEncoder())->encode([5], ['type' => 'array', 'contains' => ['type' => 'integer']]),
            '[5]',
        );
    }

    /**
     * A key outside `properties` is declared by `additionalProperties`.
     * Dropping that schema encoded a nested empty object as `[]`, which the
     * contract then rejected as a generated-request failure.
     */
    public function jsonEncoderReadsTheAdditionalPropertiesSchema(): void
    {
        $encoder = new JsonBodyEncoder();
        $objectValued = ['type' => 'object', 'additionalProperties' => ['type' => 'object']];

        Assert::same($encoder->encode(['k' => []], $objectValued), '{"k":{}}');
        Assert::same($encoder->encode(['k' => ['n' => []]], ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['n' => ['type' => 'object']]]]), '{"k":{"n":{}}}');
        // A boolean carries no shape, so an empty value stays a list.
        Assert::same($encoder->encode(['k' => []], ['type' => 'object', 'additionalProperties' => true]), '{"k":[]}');
        // A declared property still wins over the additional schema.
        Assert::same(
            $encoder->encode(['k' => []], ['type' => 'object', 'properties' => ['k' => []], 'additionalProperties' => ['type' => 'object']]),
            '{"k":[]}',
        );
        Assert::same(
            $encoder->encode(['k' => []], ['type' => 'object', 'unevaluatedProperties' => ['type' => 'object']]),
            '{"k":{}}',
        );
        Assert::same(
            $encoder->encode(['kind' => 'x', 'detail' => []], [
                'type' => 'object',
                'dependentSchemas' => ['kind' => ['properties' => ['detail' => ['type' => 'object']]]],
            ]),
            '{"kind":"x","detail":{}}',
        );
    }

    public function encodesTheTailOfAPrefixTupleAndNestedApplicators(): void
    {
        $encoder = new JsonBodyEncoder();

        // An element past `prefixItems` is declared by `contains` when there
        // is no `items`, and by `unevaluatedItems` when there is neither.
        Assert::same(
            $encoder->encode(['a', 2, 3], [
                'type' => 'array',
                'prefixItems' => [['type' => 'string']],
                'contains' => ['type' => 'integer'],
            ]),
            '["a",2,3]',
        );
        Assert::same(
            $encoder->encode([[], []], [
                'type' => 'array',
                'unevaluatedItems' => ['type' => 'object'],
            ]),
            '[{},{}]',
        );

        // An `allOf` branch that declares the array shape the root leaves out.
        Assert::same(
            $encoder->encode(['k' => []], [
                'allOf' => [['properties' => ['k' => ['type' => 'object']]]],
            ]),
            '{"k":{}}',
        );

        // A dependent branch contributes its members only when the trigger is
        // there; an absent trigger leaves the member undeclared, and an
        // undeclared empty member keeps the array encoding it arrived with.
        Assert::same(
            $encoder->encode(['other' => []], [
                'type' => 'object',
                'dependentSchemas' => ['kind' => ['properties' => ['other' => ['type' => 'object']]]],
            ]),
            '{"other":[]}',
        );

        // `items` wins over `contains`: the first element reads as a string,
        // which only encodes as one if `items` was consulted.
        Assert::same(
            $encoder->encode(['x'], ['type' => 'array', 'items' => ['type' => 'string'], 'contains' => ['type' => 'integer']]),
            '["x"]',
        );

        // The root's `$defs` is what a local `$ref` below it resolves against,
        // and the referenced shape decides the encoding.
        Assert::same(
            $encoder->encode(
                ['k' => []],
                [
                    'type' => 'object',
                    '$defs' => ['node' => ['type' => 'object', 'properties' => ['k' => ['type' => 'object']]]],
                    'additionalProperties' => ['$ref' => '#/$defs/node'],
                ],
            ),
            '{"k":{}}',
        );
    }

    public function aLocalReferenceIsResolvedAgainstTheRootDefs(): void
    {
        $encoder = new JsonBodyEncoder();

        // The name is JSON-Pointer-escaped: `a/b` is written `a~1b`, and a
        // `~` in a name is written `~0`. Both resolve, and the referenced shape
        // is what decides the encoding.
        Assert::same(
            $encoder->encode(
                ['k' => []],
                [
                    '$defs' => ['a/b~c' => ['type' => 'object', 'properties' => ['k' => ['type' => 'object']]]],
                    '$ref' => '#/$defs/a~1b~0c',
                ],
            ),
            '{"k":{}}',
        );
    }

    /**
     * A `$ref` the encoder cannot resolve is refused by name: it would
     * otherwise encode the value against no schema at all, and a nested empty
     * object as `[]` — the exact loss the local form exists to prevent.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('unresolvableReferences')]
    public function anUnresolvableReferenceIsRefused(array $schema, string $reason): void
    {
        Expect::exception(InvalidCase::class);
        (new JsonBodyEncoder())->encode(['k' => []], [...$schema, 'type' => 'object']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unresolvableReferences(): iterable
    {
        yield 'a remote pointer' => [['$ref' => 'other.json#/$defs/node'], 'not local'];
        yield 'a non-string pointer' => [['$ref' => 42], 'not local'];
        yield 'another root' => [['$ref' => '#/definitions/node'], 'not local'];
        yield 'an absent member' => [
            ['$defs' => ['node' => ['type' => 'object']], '$ref' => '#/$defs/missing'],
            'names no $defs member',
        ];
        yield 'a defs that is a list' => [
            ['$defs' => [['type' => 'object']], '$ref' => '#/$defs/node'],
            'names no $defs member',
        ];
    }

    public function aDefsThatIsAListCarriesNoMemberToName(): void
    {
        foreach ([
            ['$defs' => [['type' => 'object']]],
            ['$defs' => 'invalid'],
        ] as $schema) {
            try {
                (new JsonBodyEncoder())->encode(['k' => []], ['type' => 'object', ...$schema]);
                Assert::true(actual: false);
            } catch (InvalidCase) {
                Assert::true(actual: true);
            }
        }
    }

    public function anApplicatorBranchContributesTheShapeTheRootLacks(): void
    {
        $encoder = new JsonBodyEncoder();

        // The root says only `allOf`; each of the three shape keywords a
        // branch may carry decides the encoding on its own, and asking for one
        // proves nothing about the others.
        Assert::same(
            $encoder->encode(['a', 'b'], ['allOf' => [['type' => 'array', 'items' => ['type' => 'string']]]]),
            '["a","b"]',
        );
        Assert::same(
            $encoder->encode([1, 2], ['allOf' => [['type' => 'array', 'items' => ['type' => 'integer']]]]),
            '[1,2]',
        );
        Assert::same(
            $encoder->encode(
                ['first' => [], 'second' => []],
                ['allOf' => [['type' => 'object', 'properties' => ['first' => ['type' => 'object'], 'second' => ['type' => 'object']]]]],
            ),
            '{"first":{},"second":{}}',
        );

        // `prefixItems` decides position by position, so the second element is
        // read against the second schema and not against `items`.
        Assert::same(
            $encoder->encode(['a', ['b' => []]], [
                'allOf' => [[
                    'type' => 'array',
                    'prefixItems' => [['type' => 'string'], ['type' => 'object', 'properties' => ['b' => ['type' => 'object']]]],
                ]],
            ]),
            '["a",{"b":{}}]',
        );

        // A shape the root already declares wins: the branch is not allowed
        // to overrule it, so the encoding follows the root.
        Assert::same(
            $encoder->encode([[]], ['type' => 'array', 'items' => ['type' => 'object'], 'allOf' => [['items' => ['type' => 'string']]]]),
            '[{}]',
        );

        // Properties merge rather than replace: a branch that declares one
        // member of an object the root declared another keeps both.
        Assert::same(
            $encoder->encode(
                ['kept' => [], 'added' => []],
                [
                    'allOf' => [
                        ['properties' => ['kept' => ['type' => 'object']]],
                        ['properties' => ['added' => ['type' => 'object']]],
                    ],
                ],
            ),
            '{"kept":{},"added":{}}',
        );

        // A branch that is not an object cannot contribute a shape.
        Assert::same($encoder->encode(['a', 'b'], ['allOf' => ['ignored', ['type' => 'array', 'items' => ['type' => 'string']]]]), '["a","b"]');
    }

    /**
     * A numeric member name is a name. PHP normalizes the array key `"2020"`
     * to an integer, and refusing it made a legal document impossible to
     * generate — with a message about `properties` not being an object, which
     * it was.
     */
    public function jsonEncoderKeepsNumericObjectKeys(): void
    {
        $encoder = new JsonBodyEncoder();

        Assert::same($encoder->encode([2020 => 'x'], ['type' => 'object']), '{"2020":"x"}');
        Assert::same(
            $encoder->encode(['2020' => ['a' => 1]], ['type' => 'object', 'properties' => ['2020' => ['type' => 'object', 'properties' => ['a' => ['type' => 'integer']]]]]),
            '{"2020":{"a":1}}',
        );
        // Non-sequential numeric names are still an object.
        Assert::same(
            $encoder->encode(['5' => 'a', '3' => 'b'], ['type' => 'object', 'properties' => ['5' => ['type' => 'string'], '3' => ['type' => 'string']]]),
            '{"5":"a","3":"b"}',
        );
        // The one shape this cannot represent: names running 0, 1, … without a
        // gap are a PHP list, and a list is what a negative case sends when it
        // means to violate an object schema. Pinned so the limit is a decision
        // and not a surprise.
        Assert::same(
            $encoder->encode(['0' => 'a', '1' => 'b'], ['type' => 'object', 'properties' => ['0' => ['type' => 'string'], '1' => ['type' => 'string']]]),
            '["a","b"]',
        );
    }

    private function materializer(): ResponseMaterializer
    {
        $factory = new Psr17Factory();

        return new ResponseMaterializer($factory, $factory);
    }
}
