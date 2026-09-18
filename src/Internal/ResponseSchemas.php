<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * The response-direction view of a schema, exactly as the contract validator
 * reads a response body: a `writeOnly` property is not required on a
 * response.
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
        return $this->schemas->effective($schema, SchemaDirection::Response);
    }
}
