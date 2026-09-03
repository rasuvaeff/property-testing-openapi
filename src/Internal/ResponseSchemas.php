<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * The response-direction view of a schema: `writeOnly` properties are not
 * part of a response, so they leave `properties` and `required` the way the
 * contract validator drops them before checking a response body.
 *
 * @internal
 */
final readonly class ResponseSchemas
{
    public function __construct(
        private DirectionalSchemas $schemas = new DirectionalSchemas(),
    ) {}

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function effective(array $schema): array
    {
        return $this->schemas->effective($schema, 'writeOnly');
    }
}
