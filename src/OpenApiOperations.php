<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Data-provider helper: the suite's resolved selection as named single-argument
 * tuples, so each operation is a separate runner case under both Testo
 * (`#[DataProvider]`) and PHPUnit (`#[DataProviderExternal]`).
 *
 * ```php
 * public static function operations(): iterable
 * {
 *     return OpenApiOperations::keys(self::suite());
 * }
 * ```
 *
 * @api
 */
final readonly class OpenApiOperations
{
    /** @return iterable<string, array{string}> */
    public static function keys(ContractSuite $suite): iterable
    {
        foreach ($suite->operationKeys() as $key) {
            yield $key => [$key];
        }
    }
}
