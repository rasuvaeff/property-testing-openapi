<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Which declared media type the body-side categories read their schema from,
 * over hand-built operations: a document the contract would refuse is the
 * only way such an entry still reaches the finder.
 */
#[Test]
#[Covers(BodyTargets::class)]
final class BodyTargetsTest
{
    /**
     * A malformed entry says nothing about the ones after it: `content` is a
     * map, and the next JSON media type is still the body.
     */
    #[DataProvider('skippedEntryProvider')]
    public function aMalformedEntryIsSkippedForTheNextOne(array $content): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => $content]);

        Assert::same((new BodyTargets())->jsonBody($operation), ['mediaType' => 'application/vnd.op+json', 'schema' => ['type' => 'object']]);
    }

    public static function skippedEntryProvider(): iterable
    {
        $next = ['application/vnd.op+json' => ['schema' => ['type' => 'object']]];

        yield 'a JSON media type whose definition is not an object' => [['application/json' => 'not-a-media-type-object', ...$next]];
        yield 'a numeric media type key' => [[7 => ['schema' => ['type' => 'string']], ...$next]];
        yield 'an empty media type key' => [['' => ['schema' => ['type' => 'string']], ...$next]];
        yield 'a non-JSON media type' => [['text/plain' => ['schema' => ['type' => 'string']], ...$next]];
    }
}
