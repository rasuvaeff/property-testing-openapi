<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;

/**
 * The run-time check of the response case shape at the `@api` boundary, the
 * twin of {@see CaseShape} for requests: a hand-written response case without
 * a key used to raise a PHP warning inside the materializer; it is refused by
 * the name of the missing key now (#165). The shape itself is declared once,
 * on {@see \Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary}
 * (`ResponseCaseData`).
 *
 * @internal
 */
final readonly class ResponseCaseShape
{
    /**
     * @param array<array-key, mixed> $case
     */
    public static function assert(array $case): void
    {
        foreach (['operationKey', 'status', 'headers', 'body', 'misuse'] as $key) {
            if (!array_key_exists($key, $case)) {
                throw InvalidCase::missingKey($key);
            }
        }
        if (!is_string($case['operationKey'] ?? null)) {
            throw new InvalidCase('Response case "operationKey" must be a string');
        }
        if (!is_int($case['status'] ?? null)) {
            throw new InvalidCase('Response case "status" must be an integer');
        }
        if (!is_array($case['headers'] ?? null)) {
            throw new InvalidCase('Response case "headers" must be a map of header values');
        }
        $body = $case['body'] ?? null;
        if ($body !== null && (!is_array($body) || !is_string($body['mediaType'] ?? null)
            || !in_array($body['encoding'] ?? null, ['json', 'raw'], strict: true) || !array_key_exists('value', $body))) {
            throw new InvalidCase('Response case "body" must be null or carry a string "mediaType", an "encoding" of "json" or "raw", and a "value"');
        }
        $misuse = $case['misuse'] ?? null;
        if ($misuse !== null && (!is_array($misuse) || !is_string($misuse['kind'] ?? null) || !is_string($misuse['location'] ?? null) || !is_string($misuse['name'] ?? null))) {
            throw new InvalidCase('Response case "misuse" must be null or carry string "kind", "location" and "name" members');
        }
    }
}
