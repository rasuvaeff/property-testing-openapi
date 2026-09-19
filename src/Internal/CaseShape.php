<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;

/**
 * The run-time check of the exported case shape at the `@api` boundary: a
 * hand-written case without `misuse` used to raise a PHP warning and pass
 * `checkValid()`; it is refused by the name of the missing key now (#128).
 * The shape itself is declared once, on {@see \Rasuvaeff\PropertyTesting\OpenApi\ContractSuite}
 * (`CaseData`), and imported everywhere else.
 *
 * @internal
 */
final readonly class CaseShape
{
    private const array PARAMETER_MAPS = ['path', 'query', 'headers', 'cookies'];

    /**
     * @param array<array-key, mixed> $case
     */
    public static function assert(array $case): void
    {
        foreach (['operationKey', ...self::PARAMETER_MAPS, 'body', 'misuse'] as $key) {
            if (!array_key_exists($key, $case)) {
                throw InvalidCase::missingKey($key);
            }
        }
        if (!is_string($case['operationKey'])) {
            throw new InvalidCase('Case "operationKey" must be a string');
        }
        foreach (self::PARAMETER_MAPS as $key) {
            if (!is_array($case[$key])) {
                throw new InvalidCase(sprintf('Case "%s" must be a map of parameter values', $key));
            }
        }
        $body = $case['body'];
        if ($body !== null) {
            if (!is_array($body) || !is_string($body['encoding'] ?? null) || !is_string($body['mediaType'] ?? null)) {
                throw new InvalidCase('Case "body" must be null or carry string "encoding" and "mediaType" members');
            }
        }
        $misuse = $case['misuse'];
        if ($misuse !== null) {
            if (!is_array($misuse) || !is_string($misuse['kind'] ?? null) || !is_string($misuse['location'] ?? null) || !is_string($misuse['name'] ?? null)) {
                throw new InvalidCase('Case "misuse" must be null or carry string "kind", "location" and "name" members');
            }
        }
    }
}
