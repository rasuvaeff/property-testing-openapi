<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * Hand-built operations pinning how a generated value is laid out: every
 * member of an object parameter, every item of a binary part list, and the
 * part shapes the multipart writer refuses or ignores.
 */
#[Test]
#[Covers(RequestCaseArbitrary::class)]
final class RequestCaseArbitraryPartsTest
{
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
}
