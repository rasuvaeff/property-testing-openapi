<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ParameterSerializer::class)]
final class ParameterSerializerTest
{
    /** @param string|list<string>|array<string, string> $value */
    #[DataProvider('serializationCases')]
    public function serializesEverySupportedShape(
        string $name,
        string|array $value,
        string $style,
        bool $explode,
        bool $allowReserved,
        string $expected,
    ): void {
        Assert::same((new ParameterSerializer())->serialize($name, $value, $style, $explode, $allowReserved), $expected);
    }

    /** @return iterable<string, array{string, string|list<string>|array<string, string>, string, bool, bool, string}> */
    public static function serializationCases(): iterable
    {
        yield 'simple scalar encoded' => ['value', 'a/b c', 'simple', false, false, 'a%2Fb%20c'];
        yield 'simple scalar reserved' => ['value', 'a/b?c', 'simple', false, true, 'a/b?c'];
        yield 'simple list' => ['value', ['a/b', 'c d'], 'simple', false, false, 'a%2Fb,c%20d'];
        yield 'simple object compact' => ['value', ['a/b' => 'c d', 'x' => 'y'], 'simple', false, false, 'a%2Fb,c%20d,x,y'];
        yield 'simple object exploded' => ['value', ['a' => 'b', 'x' => 'y'], 'simple', true, false, 'a=b,x=y'];
        yield 'label list' => ['value', ['a', 'b'], 'label', true, false, '.a.b'];
        // RFC 6570 reserves the repeated dot for the exploded form; an
        // unexploded label array is comma-separated.
        yield 'label list without explode' => ['value', ['a', 'b'], 'label', false, false, '.a,b'];
        yield 'label scalar' => ['value', 'a/b c', 'label', false, false, '.a%2Fb%20c'];
        yield 'label object' => ['value', ['a' => 'b', 'x' => 'y'], 'label', true, false, '.a=b.x=y'];
        // The object branch is comma-separated in the unexploded form
        // regardless of the separator the list branch uses.
        yield 'label object without explode' => ['value', ['a' => 'b', 'x' => 'y'], 'label', false, false, '.a,b,x,y'];
        yield 'matrix scalar' => ['a/b', 'c d', 'matrix', false, false, ';a%2Fb=c%20d'];
        yield 'matrix list compact' => ['id', ['a', 'b'], 'matrix', false, false, ';id=a,b'];
        yield 'matrix list compact encoded' => ['id', ['a/b', 'c d'], 'matrix', false, false, ';id=a%2Fb,c%20d'];
        yield 'matrix list exploded' => ['id', ['a', 'b'], 'matrix', true, false, ';id=a;id=b'];
        yield 'matrix object compact' => ['id', ['a' => 'b', 'x' => 'y'], 'matrix', false, false, ';id=a,b,x,y'];
        yield 'matrix object exploded' => ['id', ['a' => 'b', 'x' => 'y'], 'matrix', true, false, ';a=b;x=y'];
        yield 'form scalar' => ['a/b', 'c d', 'form', false, false, 'a%2Fb=c%20d'];
        yield 'form list compact' => ['id', ['a/b', 'c d'], 'form', false, false, 'id=a%2Fb,c%20d'];
        yield 'form list exploded' => ['id', ['a', 'b'], 'form', true, false, 'id=a&id=b'];
        yield 'form object compact' => ['id', ['a' => 'b', 'x' => 'y'], 'form', false, false, 'id=a,b,x,y'];
        yield 'form object exploded' => ['id', ['a' => 'b', 'x' => 'y'], 'form', true, false, 'a=b&x=y'];
        // A raw space is not a legal URI character, so the separator itself
        // travels encoded; the OpenAPI style table spells the pipe raw.
        yield 'space delimited' => ['id', ['a/b', 'cd'], 'spaceDelimited', false, false, 'id=a%2Fb%20cd'];
        yield 'pipe delimited' => ['id', ['a/b', 'c d'], 'pipeDelimited', false, false, 'id=a%2Fb|c%20d'];
        yield 'deep object' => ['filter', ['a/b' => 'c d', 'x' => 'y'], 'deepObject', true, false, 'filter%5Ba%2Fb%5D=c%20d&filter%5Bx%5D=y'];
        yield 'empty deep object' => ['filter', [], 'deepObject', true, false, ''];
    }

    #[DataProvider('invalidShapeCases')]
    public function rejectsInvalidShapes(string|array $value, string $style): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new ParameterSerializer())->serialize('value', $value, $style, explode: true);
    }

    public function preservesReservedCharactersInEveryEncodedListForm(): void
    {
        $serializer = new ParameterSerializer();

        Assert::same($serializer->serialize('value', ['a/b', 'c?d'], 'simple', explode: false), 'a%2Fb,c%3Fd');
        Assert::same($serializer->serialize('value', ['a/b', 'c?d'], 'form', explode: false), 'value=a%2Fb,c%3Fd');
        Assert::same($serializer->serialize('value', ['a/b', 'c?d'], 'spaceDelimited', explode: false), 'value=a%2Fb%20c%3Fd');
        Assert::same($serializer->serialize('value', ['a/b', 'c?d'], 'pipeDelimited', explode: false), 'value=a%2Fb|c%3Fd');
        Assert::same($serializer->serialize('value', ['a/b', 'c?d'], 'simple', explode: false, allowReserved: true), 'a/b,c?d');
    }

    public function keepsQueryDelimitersEncodedWhenAllowReservedIsEnabled(): void
    {
        $serializer = new ParameterSerializer();

        Assert::same($serializer->serialize('value', 'a&b=c#fragment', 'form', explode: true, allowReserved: true), 'value=a%26b%3Dc%23fragment');
    }

    public function escapesLabelDotsSoListBoundariesRemainUnambiguous(): void
    {
        $serializer = new ParameterSerializer();

        Assert::same($serializer->serialize('value', ['a.b', 'c'], 'label', explode: false), '.a%2Eb,c');
        Assert::same($serializer->serialize('value', ['a.b' => 'c.d'], 'label', explode: true), '.a%2Eb=c%2Ed');
    }

    /**
     * Neither style can escape its own separator: the separator and an encoded
     * item character are the same octets on the wire, so such a value has no
     * representation and generating one would emit an ambiguous request.
     */
    #[DataProvider('unrepresentableDelimitedCases')]
    public function refusesDelimitedValuesCarryingTheirOwnSeparator(array $value, string $style, string $message): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new ParameterSerializer())->serialize('value', $value, $style, explode: false);
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function unrepresentableDelimitedCases(): iterable
    {
        yield 'space inside a space-delimited item' => [['a b', 'c'], 'spaceDelimited', 'Delimited query parameter values cannot contain " "'];
        yield 'pipe inside a pipe-delimited item' => [['a|b', 'c'], 'pipeDelimited', 'Delimited query parameter values cannot contain "|"'];
    }

    public function rejectsDelimitedNonListValuesWithoutTypeCoercion(): void
    {
        $serializer = new ParameterSerializer();

        Expect::exception(UnsupportedGeneration::class);
        $serializer->serialize('value', ['key' => 'item'], 'spaceDelimited', explode: false);
    }

    public function rejectsUnknownStyleExplicitly(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        (new ParameterSerializer())->serialize('value', 'item', 'unsupported', explode: false);
    }

    public function rejectsNonStringItemsInListShapes(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new ParameterSerializer())->serialize('value', ['item', 42], 'simple', explode: false);
    }

    public function keepsEmptyObjectAndListBoundariesObservable(): void
    {
        $serializer = new ParameterSerializer();

        Assert::same($serializer->serialize('value', [], 'deepObject', explode: true), '');
        Assert::same($serializer->serialize('value', ['key' => 'item'], 'deepObject', explode: true), 'value%5Bkey%5D=item');
        Assert::same($serializer->serialize('value', [], 'simple', explode: false), '');
    }

    public function rejectsADeepObjectListWithTheDeepObjectMessage(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('deepObject parameters require an object value');

        (new ParameterSerializer())->serialize('value', ['item'], 'deepObject', explode: true);
    }

    /** @return iterable<string, array{string|list<string>|array<string, string>, string}> */
    public static function invalidShapeCases(): iterable
    {
        yield 'space scalar' => ['value', 'spaceDelimited'];
        yield 'space object' => [['key' => 'value'], 'spaceDelimited'];
        yield 'pipe scalar' => ['value', 'pipeDelimited'];
        yield 'pipe object' => [['key' => 'value'], 'pipeDelimited'];
        yield 'deep scalar' => ['value', 'deepObject'];
        yield 'deep list' => [['value'], 'deepObject'];
        yield 'object non-string key' => [[1 => 'value'], 'simple'];
        yield 'object non-string value' => [['key' => 42], 'simple'];
        yield 'unknown style' => ['value', 'unknown'];
    }
}
