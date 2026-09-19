<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\DirectionalSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RequestSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ResponseSchemas;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DirectionalSchemas::class)]
#[Covers(RequestSchemas::class)]
#[Covers(ResponseSchemas::class)]
final class DirectionalSchemasTest
{
    private const array SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'name', 'secret', 7],
        'properties' => [
            'id' => ['type' => 'integer', 'readOnly' => true],
            'name' => ['type' => 'string'],
            'secret' => ['type' => 'string', 'writeOnly' => true],
            'nested' => ['type' => 'object', 'required' => ['at'], 'properties' => ['at' => ['type' => 'string', 'readOnly' => true], 'note' => ['type' => 'string']]],
            'list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['at' => ['readOnly' => true], 'v' => []]]],
            'bad' => 'not a schema',
        ],
        'oneOf' => [['properties' => ['x' => ['readOnly' => true, 'type' => 'string']]], 'x'],
        'allOf' => [['properties' => ['y' => ['readOnly' => true]]]],
        'anyOf' => [['properties' => ['z' => ['writeOnly' => true]]]],
    ];

    /**
     * The schema view is the contract's own rewrite, not a copy of it: a
     * property the other direction owns loses its `required` entry and keeps
     * its subschema, recursively — including under `additionalProperties`,
     * which the copy this package used to carry never visited.
     */
    public function requestViewIsTheContractsRewrite(): void
    {
        $schema = self::SCHEMA + ['additionalProperties' => ['type' => 'object', 'required' => ['at'], 'properties' => ['at' => ['readOnly' => true]]]];

        $view = (new RequestSchemas())->effective($schema);

        Assert::same($view, (new SchemaCheck())->effective($schema, SchemaDirection::Request));
        Assert::same($view['required'], ['name', 'secret', 7]);
        Assert::same(array_keys($view['properties']), ['id', 'name', 'secret', 'nested', 'list', 'bad']);
        Assert::same($view['properties']['nested']['required'], []);
        Assert::same($view['additionalProperties']['required'], []);
    }

    public function responseViewIsTheContractsRewrite(): void
    {
        $view = (new ResponseSchemas())->effective(self::SCHEMA);

        Assert::same($view, (new SchemaCheck())->effective(self::SCHEMA, SchemaDirection::Response));
        Assert::same($view['required'], ['id', 'name', 7]);
        Assert::same(array_keys($view['properties']), ['id', 'name', 'secret', 'nested', 'list', 'bad']);
        Assert::same($view['properties']['nested'], self::SCHEMA['properties']['nested']);
    }

    public function leavesSchemasWithoutFlagsUntouched(): void
    {
        $schemas = new DirectionalSchemas();

        foreach ([['type' => 'string'], ['properties' => 'x', 'required' => ['a']], ['items' => ['a']], ['allOf' => 'x'], []] as $schema) {
            Assert::same($schemas->effective($schema, SchemaDirection::Request), $schema);
            Assert::same($schemas->effective($schema, SchemaDirection::Response), $schema);
        }
    }

    public function requestViewDropsReadOnlyMembersFromAValue(): void
    {
        $value = (new RequestSchemas())->value([
            'id' => 7,
            'name' => 'Ann',
            'secret' => 's',
            'nested' => ['at' => 'x', 'note' => 'n', 'extra' => 1],
            'list' => [['at' => 'x', 'v' => 1], ['v' => 2], 'scalar'],
            'bad' => ['at' => 'kept'],
            'unknown' => ['at' => 'kept'],
        ], self::SCHEMA);

        Assert::same($value, [
            'name' => 'Ann',
            'secret' => 's',
            'nested' => ['note' => 'n', 'extra' => 1],
            'list' => [['v' => 1], ['v' => 2], 'scalar'],
            'bad' => ['at' => 'kept'],
            'unknown' => ['at' => 'kept'],
        ]);
    }

    public function valueViewLeavesScalarsListsWithoutItemsAndObjectsWithoutPropertiesAlone(): void
    {
        $schemas = new RequestSchemas();

        Assert::same($schemas->value('x', self::SCHEMA), 'x');
        Assert::same($schemas->value(['a', 'b'], ['type' => 'array']), ['a', 'b']);
        Assert::same($schemas->value(['a', 'b'], ['items' => ['x']]), ['a', 'b']);
        Assert::same($schemas->value(['k' => 'v'], ['type' => 'object']), ['k' => 'v']);
        Assert::same($schemas->value(['1' => 'a', '2' => 'b'], ['properties' => ['1' => ['readOnly' => true]]]), [2 => 'b']);
    }
}
