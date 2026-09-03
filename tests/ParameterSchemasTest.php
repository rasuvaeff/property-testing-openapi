<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ParameterSchemas::class)]
final class ParameterSchemasTest
{
    public function dropsNullabilityFromEveryNestedSchema(): void
    {
        $schemas = new ParameterSchemas();

        $view = $schemas->forLocation([
            'type' => 'object',
            'nullable' => true,
            'properties' => ['a' => ['type' => 'integer', 'nullable' => true], 'b' => 'x'],
            'additionalProperties' => ['type' => 'string', 'nullable' => true],
            'not' => ['type' => 'null', 'nullable' => true],
            'anyOf' => [['type' => 'string', 'nullable' => true], 'x'],
            'items' => ['type' => 'string', 'nullable' => true, 'enum' => [null, 'a']],
            'allOf' => [['nullable' => true, 'type' => 'integer']],
            'oneOf' => ['k' => ['type' => 'string', 'nullable' => true]],
        ], 'query');

        Assert::same($view, [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'integer'], 'b' => 'x'],
            'additionalProperties' => ['type' => 'string'],
            'not' => ['type' => 'null'],
            'anyOf' => [['type' => 'string'], 'x'],
            'items' => ['type' => 'string', 'enum' => ['a']],
            'allOf' => [['type' => 'integer']],
            'oneOf' => ['k' => ['type' => 'string', 'nullable' => true]],
        ]);
    }

    public function leavesLengthsAloneOutsideThePath(): void
    {
        $schemas = new ParameterSchemas();

        foreach (['query', 'header', 'cookie'] as $location) {
            Assert::same($schemas->forLocation(['type' => 'string'], $location), ['type' => 'string']);
            Assert::same($schemas->forLocation(['type' => 'string', 'format' => 'uri'], $location), ['type' => 'string', 'format' => 'uri']);
            Assert::same($schemas->forLocation(['enum' => ['', 'a/b']], $location), ['enum' => ['', 'a/b']]);
        }
    }

    public function raisesTheMinimumLengthOfEveryPathString(): void
    {
        $schemas = new ParameterSchemas();

        Assert::same($schemas->forLocation(['type' => 'string'], 'path'), ['type' => 'string', 'minLength' => 1]);
        Assert::same($schemas->forLocation(['type' => 'string', 'minLength' => 0], 'path'), ['type' => 'string', 'minLength' => 1]);
        Assert::same($schemas->forLocation(['type' => 'string', 'minLength' => 3], 'path'), ['type' => 'string', 'minLength' => 3]);
        Assert::same($schemas->forLocation(['type' => 'string', 'minLength' => 'x'], 'path'), ['type' => 'string', 'minLength' => 'x']);
        Assert::same($schemas->forLocation(['maxLength' => 4], 'path'), ['maxLength' => 4, 'minLength' => 1]);
        Assert::same($schemas->forLocation(['pattern' => '^a'], 'path'), ['pattern' => '^a', 'minLength' => 1]);
        Assert::same($schemas->forLocation(['type' => ['string', 'integer']], 'path'), ['type' => ['string', 'integer'], 'minLength' => 1]);
        Assert::same($schemas->forLocation(['type' => 'integer'], 'path'), ['type' => 'integer']);
        Assert::same($schemas->forLocation(['type' => 'string', 'format' => 'uuid'], 'path'), ['type' => 'string', 'format' => 'uuid', 'minLength' => 1]);
        Assert::same(
            $schemas->forLocation(['type' => 'array', 'items' => ['type' => 'string']], 'path'),
            ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]],
        );
        Assert::same(
            $schemas->forLocation(['type' => 'object', 'properties' => ['k' => ['type' => 'string']], 'additionalProperties' => ['type' => 'string']], 'path'),
            ['type' => 'object', 'properties' => ['k' => ['type' => 'string', 'minLength' => 1]], 'additionalProperties' => ['type' => 'string', 'minLength' => 1]],
        );
    }

    public function keepsOnlySegmentSafeEnumMembersOnThePath(): void
    {
        $schemas = new ParameterSchemas();

        Assert::same($schemas->forLocation(['type' => 'string', 'enum' => ['', 'a/b', 'ok', 'x\\y', null]], 'path'), ['type' => 'string', 'enum' => ['ok'], 'minLength' => 1]);
        Assert::same($schemas->forLocation(['enum' => [1, 2]], 'path'), ['enum' => [1, 2]]);
        Assert::same($schemas->forLocation(['const' => 'fixed'], 'path'), ['const' => 'fixed']);
        Assert::same($schemas->forLocation(['const' => 3], 'path'), ['const' => 3]);
    }

    #[DataProvider('unsupportedPathSchemas')]
    public function failsClosedOnSchemasNoSegmentCanCarry(array $schema, string $location, string $message): void
    {
        try {
            (new ParameterSchemas())->forLocation($schema, $location);
            Assert::true(actual: false, message: 'Expected unsupported generation exception');
        } catch (UnsupportedGeneration $exception) {
            Assert::same($exception->getMessage(), 'Unsupported OpenAPI schema generation: ' . $message);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function unsupportedPathSchemas(): iterable
    {
        yield 'empty const' => [['const' => ''], 'path', 'path parameter const cannot be carried by a template segment'];
        yield 'slash const' => [['const' => 'a/b'], 'path', 'path parameter const cannot be carried by a template segment'];
        yield 'list const with backslash' => [['const' => ['a', 'b\\c']], 'path', 'path parameter const cannot be carried by a template segment'];
        yield 'only unsafe enum members' => [['enum' => ['', '/']], 'path', 'no path parameter enum member can be carried by a template segment'];
        yield 'uri format' => [['type' => 'string', 'format' => 'uri'], 'path', 'path parameter format "uri" always carries a slash'];
        yield 'url format' => [['format' => 'url'], 'path', 'path parameter format "url" always carries a slash'];
        yield 'uri-reference in items' => [['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uri-reference']], 'path', 'path parameter format "uri-reference" always carries a slash'];
        yield 'null const anywhere' => [['const' => null], 'query', 'a parameter cannot carry a null const'];
        yield 'null-only enum anywhere' => [['enum' => [null]], 'header', 'a parameter enum needs a non-null member'];
    }

    public function judgesPathSafetyOfEveryStringInAValue(): void
    {
        $schemas = new ParameterSchemas();

        Assert::true($schemas->isPathSafe('a'));
        Assert::true($schemas->isPathSafe(42));
        Assert::true($schemas->isPathSafe(null));
        Assert::true($schemas->isPathSafe(['a', 'b']));
        Assert::true($schemas->isPathSafe(['k' => 'v', 'n' => 1]));
        Assert::true($schemas->isPathSafe([]));
        Assert::false($schemas->isPathSafe(''));
        Assert::false($schemas->isPathSafe('a/b'));
        Assert::false($schemas->isPathSafe('a\\b'));
        Assert::false($schemas->isPathSafe(['a', '']));
        Assert::false($schemas->isPathSafe(['a', 'b/c']));
        Assert::false($schemas->isPathSafe(['k/' => 'v']));
        Assert::false($schemas->isPathSafe(['k' => '']));
    }
}
