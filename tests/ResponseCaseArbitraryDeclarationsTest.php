<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Hand-built Response Objects: shapes `Contract::fromArray()` refuses can
 * still reach the generator through an `Operation`, and each one has to be
 * skipped or placed, never read as something else.
 */
#[Test]
#[Covers(ResponseCaseArbitrary::class)]
final class ResponseCaseArbitraryDeclarationsTest
{
    public function aMalformedHeaderDeclarationIsSkippedWithoutStoppingTheScan(): void
    {
        $operation = $this->operation(['200' => ['headers' => [
            'X-Bad' => 'oops',
            'X-List' => ['oops'],
            'X-Req' => ['required' => true, 'schema' => ['type' => 'string', 'enum' => ['v']]],
        ]]]);
        $arbitrary = (new ResponseCaseArbitrary())->forOperation($operation, 200);

        foreach (range(1, 5) as $seed) {
            Assert::same($arbitrary->generate(new Random($seed))->value['headers'], ['X-Req' => 'v']);
        }
    }

    public function aMalformedContentEntryIsSkippedWithoutStoppingTheScan(): void
    {
        $operation = $this->operation(['200' => ['content' => [
            7 => ['schema' => ['type' => 'string']],
            '' => ['schema' => ['type' => 'string']],
            'application/json' => 'oops',
            'application/vnd.x+json' => ['schema' => ['type' => 'integer', 'const' => 5]],
        ]]]);

        $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['body'], ['mediaType' => 'application/vnd.x+json', 'encoding' => 'json', 'value' => 5]);
        Assert::same((new ResponseCaseArbitrary())->jsonBody($operation, 200), ['mediaType' => 'application/vnd.x+json', 'schema' => ['type' => 'integer', 'const' => 5]]);
    }

    /** @param array<string, mixed> $responses */
    #[DataProvider('placedRefusalProvider')]
    public function aCompilerRefusalNamesTheResponsePart(array $responses, string $message): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new ResponseCaseArbitrary())->forOperation($this->operation($responses), 200);
    }

    public static function placedRefusalProvider(): iterable
    {
        $empty = ['type' => 'integer', 'minimum' => 5, 'maximum' => 1];

        yield 'header' => [
            ['200' => ['headers' => ['X-A' => ['required' => true, 'schema' => $empty]]]],
            'Unsupported OpenAPI schema generation for operation "op", response "200" header "X-A": integer bounds leave no value',
        ];
        yield 'body' => [
            ['200' => ['content' => ['application/json' => ['schema' => $empty]]]],
            'Unsupported OpenAPI schema generation for operation "op", response "200" body "application/json": integer bounds leave no value',
        ];
    }

    /** @param array<array-key, mixed> $responses */
    private function operation(array $responses): Operation
    {
        return new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: $responses);
    }
}
