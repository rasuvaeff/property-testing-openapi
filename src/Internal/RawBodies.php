<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Bodies sent as they are: `text/plain` and `application/octet-stream` (#170).
 *
 * The contract reads such a payload as opaque when its media type declares no
 * schema, and as the string value of a string schema otherwise; any other
 * schema cannot be judged against an undecoded payload, and is refused here
 * for the same reason. The payload is never empty — an empty body is a
 * missing one to the validator — and is UTF-8 text even under
 * `application/octet-stream`, so the case remains JSON-compatible: an octet
 * stream of text bytes is still an octet stream. `format: binary` is the OAS
 * annotation for exactly that, not an assertion, and is read as none.
 *
 * @internal
 *
 * @psalm-import-type Schema from SchemaShape
 */
final readonly class RawBodies
{
    private const int OPAQUE_LENGTH = 64;

    public static function carries(string $mediaType): bool
    {
        $normalized = MediaType::normalize($mediaType);

        return $normalized === 'text/plain' || $normalized === 'application/octet-stream';
    }

    /**
     * @param Schema $schema the media type's schema, already narrowed to its direction
     * @return ArbitraryInterface<array{mediaType: string, encoding: 'raw', value: string}>
     */
    public static function arbitrary(string $mediaType, array $schema, SchemaArbitraryCompiler $compiler): ArbitraryInterface
    {
        if ($schema === []) {
            $payload = Gen::stringOf(1, self::OPAQUE_LENGTH);
        } else {
            if (($schema['type'] ?? null) !== 'string') {
                throw UnsupportedGeneration::forSchema(sprintf('a "%s" body is sent as it is, so its schema must be a string schema', MediaType::normalize($mediaType)));
            }
            if (($schema['format'] ?? null) === 'binary') {
                unset($schema['format']);
            }
            if (($schema['minLength'] ?? 0) === 0) {
                $schema['minLength'] = 1;
            }
            $payload = $compiler->compile($schema);
        }

        /** @var ArbitraryInterface<array{mediaType: string, encoding: 'raw', value: string}> $raw */
        $raw = Gen::map($payload, static function (mixed $value) use ($mediaType): array {
            if (!is_string($value)) {
                throw new \LogicException('A string schema produced a non-string body');
            }

            return ['mediaType' => $mediaType, 'encoding' => 'raw', 'value' => $value];
        });

        return $raw;
    }
}
