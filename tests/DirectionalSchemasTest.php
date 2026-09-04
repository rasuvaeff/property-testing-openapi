<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

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

    public function requestViewDropsReadOnlyMembersEverywhere(): void
    {
        $view = (new RequestSchemas())->effective(self::SCHEMA);

        Assert::same($view, [
            'type' => 'object',
            'required' => ['name', 'secret', 7],
            'properties' => [
                'name' => ['type' => 'string'],
                'secret' => ['type' => 'string', 'writeOnly' => true],
                'nested' => ['type' => 'object', 'required' => [], 'properties' => ['note' => ['type' => 'string']]],
                'list' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['v' => []]]],
                'bad' => 'not a schema',
            ],
            // Dropping the last property drops `properties` itself, as the
            // contract's own effective schema does: an empty map forbids
            // nothing, and what the document said about undeclared members
            // keeps saying it.
            'oneOf' => [[], 'x'],
            'allOf' => [[]],
            'anyOf' => [['properties' => ['z' => ['writeOnly' => true]]]],
        ]);
    }

    public function malformedMembersDoNotStopTheWalk(): void
    {
        $view = (new RequestSchemas())->effective([
            'properties' => ['bad' => 'x', 'id' => ['readOnly' => true], 'name' => ['type' => 'string']],
            'required' => ['bad', 'id', 'name'],
        ]);

        Assert::same($view, [
            'properties' => ['bad' => 'x', 'name' => ['type' => 'string']],
            'required' => ['bad', 'name'],
        ]);
    }

    public function responseViewDropsWriteOnlyMembersOnly(): void
    {
        $view = (new ResponseSchemas())->effective(self::SCHEMA);

        Assert::same($view['required'], ['id', 'name', 7]);
        Assert::same(array_keys($view['properties']), ['id', 'name', 'nested', 'list', 'bad']);
        Assert::same($view['properties']['nested'], self::SCHEMA['properties']['nested']);
    }

    public function leavesSchemasWithoutFlagsUntouched(): void
    {
        $schemas = new DirectionalSchemas();

        foreach ([['type' => 'string'], ['properties' => 'x', 'required' => ['a']], ['items' => ['a']], ['allOf' => 'x'], []] as $schema) {
            Assert::same($schemas->effective($schema, 'readOnly'), $schema);
            Assert::same($schemas->effective($schema, 'writeOnly'), $schema);
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
