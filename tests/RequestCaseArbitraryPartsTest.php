<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\FormBodies;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartBodies;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Presence;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Hand-built operations pinning how a generated value is laid out: every
 * member of an object parameter, every item of a binary part list, and the
 * part shapes the multipart writer refuses or ignores.
 */
#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(FormBodies::class)]
#[Covers(MultipartBodies::class)]
#[Covers(Presence::class)]
final class RequestCaseArbitraryPartsTest
{
    /**
     * A repeated part's 16-item budget yields to a declared `minItems` above
     * it (#169).
     */
    #[DataProvider('partBudgetProvider')]
    public function aRepeatedPartReachesItsDeclaredMinimum(array $items, int $shortest, int $longest): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => ['multipart/form-data' => ['schema' => [
            'type' => 'object',
            'required' => ['ids'],
            'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99]] + $items],
        ]]]]);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $counts = [];
        foreach (range(1, 60) as $seed) {
            $counts[] = count($arbitrary->generate(new Random($seed))->value['body']['parts'] ?? []);
        }

        Assert::same([min($counts), max($counts)], [$shortest, $longest]);
    }

    public static function partBudgetProvider(): iterable
    {
        yield 'under the budget' => [['minItems' => 15], 15, 16];
        yield 'past the budget' => [['minItems' => 17], 17, 17];
        yield 'past the budget with room declared' => [['minItems' => 18, 'maxItems' => 30], 18, 18];
    }

    public function anObjectParameterKeepsEveryMember(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [[
            'name' => 'f', 'in' => 'query', 'required' => true, 'style' => 'deepObject', 'explode' => true, 'allowReserved' => false,
            'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['a', 'b'], 'properties' => [
                'a' => ['type' => 'string', 'enum' => ['x']],
                'b' => ['type' => 'string', 'enum' => ['y']],
            ]],
        ]]);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);

        foreach (range(1, 5) as $seed) {
            Assert::same($arbitrary->generate(new Random($seed))->value['query'], ['f' => ['a' => 'x', 'b' => 'y']]);
        }
    }

    public function everyItemOfABinaryPartListTravelsAsBase64(): void
    {
        $parts = $this->parts(['type' => 'object', 'required' => ['f'], 'properties' => [
            'f' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'string', 'format' => 'binary']],
        ]]);

        Assert::true($parts !== []);
        foreach ($parts as $part) {
            Assert::same([$part['name'], $part['encoding'], $part['contentType']], ['f', 'base64', 'application/octet-stream']);
            Assert::true(base64_decode($part['value'], strict: true) !== false);
        }
    }

    public function aTextPartNeverCarriesPaddingTheReaderWouldTrim(): void
    {
        $parts = $this->parts(['type' => 'object', 'required' => ['t'], 'properties' => [
            't' => ['type' => 'string', 'enum' => [' a', 'b']],
        ]]);

        Assert::same(array_unique(array_column($parts, 'value')), ['b']);
    }

    public function aPartHeaderDeclarationThatIsNotAnObjectAddsNoHeader(): void
    {
        $parts = $this->parts(['type' => 'object', 'required' => ['t'], 'properties' => [
            't' => ['type' => 'string', 'enum' => ['b']],
        ]], ['t' => ['headers' => 'oops']]);

        Assert::same(array_unique(array_map(static fn(array $part): string => json_encode($part['headers'], JSON_THROW_ON_ERROR), $parts)), ['[]']);
    }

    public function anOptionalParameterIsPresentInAboutHalfTheDraws(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [[
            'name' => 'q', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'allowReserved' => false,
            'schema' => ['type' => 'string', 'enum' => ['v']],
        ]]);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $present = 0;
        foreach (range(1, 400) as $seed) {
            $present += array_key_exists('q', $arbitrary->generate(new Random($seed))->value['query']) ? 1 : 0;
        }

        Assert::true($present >= 160 && $present <= 240, sprintf('present in %d of 400 draws', $present));
    }

    public function anExplodedFormObjectIsCheckedPastAMalformedSibling(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: [
            'required' => true,
            'content' => ['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'properties' => [
                'a' => ['x'],
                'o' => ['type' => 'object', 'minProperties' => 2, 'properties' => ['x' => ['type' => 'string']]],
            ]]]],
        ]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Unsupported OpenAPI schema generation for operation "op", request body "application/x-www-form-urlencoded": form property "o" is an exploded object whose minProperties 2 cannot be met by its 1 declared properties, and its wire form carries no undeclared member');

        (new RequestCaseArbitrary())->forOperation($operation);
    }

    /**
     * Parts of ten deterministic draws, flattened.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $encoding
     * @return list<array{name: string, value: string, encoding: string, contentType: string, headers: array<string, string>}>
     */
    private function parts(array $schema, array $encoding = []): array
    {
        $definition = ['schema' => $schema] + ($encoding === [] ? [] : ['encoding' => $encoding]);
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: [
            'required' => true,
            'content' => ['multipart/form-data' => $definition],
        ]);
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $parts = [];
        foreach (range(1, 10) as $seed) {
            /** @var array{parts: list<array{name: string, value: string, encoding: string, contentType: string, headers: array<string, string>}>} $body */
            $body = $arbitrary->generate(new Random($seed))->value['body'];
            $parts = [...$parts, ...$body['parts']];
        }

        return $parts;
    }

    /**
     * A required part header is checked against its schema by the contract,
     * so its value is one the schema admits (#157).
     *
     * @param array<string, mixed> $header
     */
    #[DataProvider('partHeaderProvider')]
    public function aRequiredPartHeaderCarriesAValueItsSchemaAdmits(array $header, \Closure $holds): void
    {
        $parts = $this->parts(
            ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'string', 'enum' => ['v']]]],
            ['n' => ['headers' => ['X-Rev' => $header + ['required' => true]]]],
        );

        foreach ($parts as $part) {
            Assert::true($holds($part['headers']['X-Rev'] ?? null), json_encode($part['headers'], JSON_THROW_ON_ERROR));
        }
    }

    public static function partHeaderProvider(): iterable
    {
        yield 'no schema keeps the example' => [['example' => 'e1'], static fn(mixed $v): bool => $v === 'e1'];
        yield 'no schema keeps the literal' => [[], static fn(mixed $v): bool => $v === 'x-openapi'];
        yield 'a string schema keeps the literal' => [['schema' => ['type' => 'string']], static fn(mixed $v): bool => $v === 'x-openapi'];
        yield 'an example the schema admits' => [['schema' => ['type' => 'integer'], 'example' => 4], static fn(mixed $v): bool => $v === '4'];
        yield 'a default when the example is rejected' => [['schema' => ['type' => 'integer', 'maximum' => 5], 'example' => 9, 'default' => 2], static fn(mixed $v): bool => $v === '2'];
        yield 'a string example of a string schema spelled as a number' => [['schema' => ['type' => 'string'], 'example' => 12], static fn(mixed $v): bool => $v === '12'];
        yield 'drawn from an integer schema' => [['schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9]], static fn(mixed $v): bool => is_string($v) && preg_match('/\A[1-9]\z/', $v) === 1];
        yield 'drawn from an enum' => [['schema' => ['type' => 'string', 'enum' => ['on', 'off']]], static fn(mixed $v): bool => $v === 'on' || $v === 'off'];
        yield 'an example with padding is passed over' => [['schema' => ['type' => 'string'], 'example' => ' e '], static fn(mixed $v): bool => $v === 'x-openapi'];
        yield 'a list schema takes a candidate as one item' => [['schema' => ['type' => 'array', 'items' => ['type' => 'string']]], static fn(mixed $v): bool => $v === 'x-openapi'];
        yield 'an optional header is not sent' => [['required' => false, 'schema' => ['type' => 'integer']], static fn(mixed $v): bool => $v === null];
    }

    /**
     * @param array<string, mixed> $header
     */
    #[DataProvider('unproducibleHeaderProvider')]
    public function aRequiredPartHeaderNoValueOfWhichCanBeGeneratedIsRefused(array $header): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation for operation "op", request body "multipart/form-data": multipart property "n" requires header "X-Rev", and no value its schema admits can be generated');

        $this->parts(
            ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'string']]],
            ['n' => ['headers' => ['X-Rev' => $header + ['required' => true]]]],
        );
    }

    public static function unproducibleHeaderProvider(): iterable
    {
        yield 'an object schema' => [['schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]]]];
        yield 'a list schema no candidate fits' => [['schema' => ['type' => 'array', 'items' => ['type' => 'integer']]]];
        yield 'a schema the generator refuses' => [['schema' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 2]]];
        yield 'a schema whose every value the header wire refuses' => [['schema' => ['type' => 'string', 'pattern' => '^ $']]];
    }

    /**
     * The part's content type is settled when the operation is compiled: an
     * optional part under one the generator cannot write is refused even
     * though most draws would leave it out (#156).
     */
    public function anOptionalPartUnderAnUnwritableMediaTypeIsRefusedAtCompileTime(): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation for operation "op", request body "multipart/form-data": Multipart property "x" declares content type "application/xml", which this generator can write neither as text nor as JSON');

        (new RequestCaseArbitrary())->forOperation(new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: [
            'required' => false,
            'content' => ['multipart/form-data' => [
                'schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string'], 'r' => ['type' => 'string', 'readOnly' => true]]],
                'encoding' => ['x' => ['contentType' => 'application/xml'], 'r' => ['contentType' => 'application/xml']],
            ]],
        ]));
    }

    public function aBinaryPartIsWrittenUnderAnyMediaType(): void
    {
        $parts = $this->parts(['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string', 'format' => 'binary']]], ['b' => ['contentType' => 'image/png']]);

        foreach ($parts as $part) {
            Assert::same([$part['encoding'], $part['contentType']], ['base64', 'image/png']);
        }
    }

    /**
     * A parameter nesting an object or a list is refused when the operation
     * is compiled, not on the draw that first produces a nested value (#156).
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('nestedParameterProvider')]
    public function aNestedParameterIsRefusedAtCompileTime(array $schema): void
    {
        Expect::exception(UnsupportedGeneration::class)
            ->withMessage('Unsupported OpenAPI schema generation for operation "op", query parameter "f": Parameter values must be scalar, arrays, or objects with scalar properties');

        (new RequestCaseArbitrary())->forOperation(new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [[
            'name' => 'f', 'in' => 'query', 'required' => false, 'style' => 'deepObject', 'explode' => true, 'allowReserved' => false, 'schema' => $schema,
        ]]));
    }

    public static function nestedParameterProvider(): iterable
    {
        yield 'an object property' => [['type' => 'object', 'properties' => ['a' => ['type' => 'object']]]];
        yield 'a list property' => [['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'string']]]]];
        yield 'an object of additional properties' => [['type' => 'object', 'additionalProperties' => ['type' => 'object']]];
        yield 'an object of pattern properties' => [['type' => 'object', 'patternProperties' => ['^a' => ['items' => ['type' => 'string']]]]];
        yield 'a list of objects' => [['type' => 'array', 'items' => ['type' => 'object']]];
        yield 'a tuple holding a list' => [['type' => 'array', 'prefixItems' => [['type' => 'string'], ['type' => 'array']]]];
    }

    public function aFlatParameterStillGenerates(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [[
            'name' => 'f', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false,
            'schema' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 2, 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9], 'prefixItems' => [['type' => 'integer', 'minimum' => 0, 'maximum' => 9]]],
        ]]);

        foreach (range(1, 5) as $seed) {
            Assert::true(is_array((new RequestCaseArbitrary())->forOperation($operation)->generate(new Random($seed))->value['query']['f'] ?? null));
        }
    }
}
