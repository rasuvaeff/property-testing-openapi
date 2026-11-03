<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Which schema an empty PHP map is read under: it is `{}` on the wire only
 * where some applicable schema says `object`, and `[]` everywhere else.
 */
#[Test]
#[Covers(JsonBodyEncoder::class)]
final class JsonBodyEncoderTest
{
    #[DataProvider('encodingProvider')]
    public function anEmptyMapIsAnObjectWhereASchemaSaysSo(mixed $value, array $schema, string $expected): void
    {
        Assert::same((new JsonBodyEncoder())->encode($value, $schema), $expected);
    }

    public static function encodingProvider(): iterable
    {
        // `contains` adds to `items`; it does not replace it.
        yield 'items keep their own type beside contains' => [
            [[]],
            ['type' => 'array', 'items' => ['type' => 'object'], 'contains' => ['minimum' => 0]],
            '[{}]',
        ];
        yield 'contains supplies the type an untyped items schema lacks' => [
            [[]],
            ['type' => 'array', 'items' => [], 'contains' => ['type' => 'object']],
            '[{}]',
        ];
        // Without `items`, `contains` is what an item is read under before
        // `unevaluatedItems`.
        yield 'contains comes before unevaluatedItems' => [
            [[]],
            ['type' => 'array', 'contains' => ['type' => 'object'], 'unevaluatedItems' => ['type' => 'string']],
            '[{}]',
        ];
        // An `allOf` branch lends the root its type and keeps every keyword
        // the root already declares.
        yield 'a branch type joins the root keywords' => [
            ['a' => []],
            ['allOf' => [['type' => 'object']], 'properties' => ['a' => ['type' => 'object']]],
            '{"a":{}}',
        ];
        // A boolean branch carries no shape and is passed over, not the end of
        // the branches.
        yield 'a boolean allOf branch does not hide the next one' => [
            [],
            ['allOf' => [true, ['type' => 'object']]],
            '{}',
        ];
        yield 'boolean items carry no shape' => [
            [[]],
            ['type' => 'array', 'items' => true],
            '[[]]',
        ];
        yield 'a boolean prefix item carries no shape' => [
            [[], []],
            ['type' => 'array', 'prefixItems' => [true], 'items' => ['type' => 'object']],
            '[[],{}]',
        ];
    }
}
