<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Hand-built operations and cases: each pins one decision of the
 * materializer on a shape the generator itself would not produce.
 */
#[Test]
#[Covers(RequestMaterializer::class)]
final class RequestMaterializerBodyTest
{
    public function refusesAHeaderValueWithTrailingWhitespaceByName(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', parameters: [[
            'name' => 'X-A', 'in' => 'header', 'required' => true, 'style' => 'simple', 'explode' => false,
            'allowReserved' => false, 'schema' => ['type' => 'string'],
        ]]);

        Expect::exception(InvalidCase::class)->withMessage('Header "X-A" carries a value no HTTP field can');

        $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'path' => [], 'query' => [], 'headers' => ['X-A' => 'a '], 'cookies' => [], 'body' => null, 'misuse' => null,
        ]);
    }

    public function anEmptyFormMemberDoesNotEndTheBody(): void
    {
        $payload = $this->formPayload(
            ['tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'name' => ['type' => 'string']],
            ['tags' => [], 'name' => 'x'],
        );

        Assert::same($payload, 'name=x');
    }

    public function aFormArrayCarriesItsItemsAsWireText(): void
    {
        $payload = $this->formPayload(
            ['tags' => ['type' => 'array', 'items' => ['type' => 'boolean']]],
            ['tags' => [true, false]],
        );

        Assert::same($payload, 'tags=true&tags=false');
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $value
     */
    #[DataProvider('malformedFormProvider')]
    public function refusesAMalformedFormMember(array $properties, array $value, string $message): void
    {
        Expect::exception(InvalidCase::class)->withMessage($message);

        $this->formPayload($properties, $value);
    }

    public static function malformedFormProvider(): iterable
    {
        yield 'object properties are a list' => [
            ['filter' => ['type' => 'object', 'properties' => ['x']]],
            ['filter' => ['a' => '1']],
            'Form object properties must be an object',
        ];
        yield 'object member schema is a list' => [
            ['filter' => ['type' => 'object', 'properties' => ['a' => ['x']]]],
            ['filter' => ['a' => '1']],
            'Form object property must be a schema object',
        ];
        yield 'scalar carries a nested list' => [
            ['name' => ['type' => 'string']],
            ['name' => [[1]]],
            'Form scalar value has an unsupported type',
        ];
    }

    public function acceptsABoundaryOfTheMaximumLength(): void
    {
        $boundary = str_repeat('b', 70);
        $request = $this->materializer()->materialize($this->bodyOperation(['multipart/form-data' => ['schema' => ['type' => 'object']]]), $this->bodyCase([
            'mediaType' => 'multipart/form-data', 'encoding' => 'multipart', 'boundary' => $boundary, 'parts' => [],
        ], null));

        Assert::same((string) $request->getBody(), '--' . $boundary . "--\r\n");
    }

    public function aDeclaredMediaTypeKeepsItsOwnSchemaUnderAMediaTypeMisuse(): void
    {
        $request = $this->materializer()->materialize($this->bodyOperation([
            'application/json' => ['schema' => []],
            'application/vnd.x+json' => ['schema' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]],
        ]), $this->bodyCase(
            ['mediaType' => 'application/vnd.x+json', 'encoding' => 'json', 'value' => ['meta' => []]],
            ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'],
        ));

        Assert::same((string) $request->getBody(), '{"meta":{}}');
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $value
     */
    private function formPayload(array $properties, array $value): string
    {
        $operation = $this->bodyOperation(['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'properties' => $properties]]]);

        return (string) $this->materializer()->materialize($operation, $this->bodyCase(
            ['mediaType' => 'application/x-www-form-urlencoded', 'encoding' => 'form', 'value' => $value],
            null,
        ))->getBody();
    }

    /** @param array<string, mixed> $content */
    private function bodyOperation(array $content): Operation
    {
        return new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => $content]);
    }

    /**
     * @param array<string, mixed> $body
     * @param null|array<string, string> $misuse
     * @return array<string, mixed>
     */
    private function bodyCase(array $body, ?array $misuse): array
    {
        return ['operationKey' => 'op', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => $body, 'misuse' => $misuse];
    }

    private function materializer(): RequestMaterializer
    {
        $factory = new Psr17Factory();

        return new RequestMaterializer($factory, $factory);
    }
}
