<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\OpenApiContract\SchemaDirection;

/**
 * The request-direction view of a schema, exactly as the contract validator
 * reads a request body: a `readOnly` property is not required on a request.
 *
 * @internal
 */
final readonly class RequestSchemas
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
        return $this->schemas->effective($schema, SchemaDirection::Request);
    }

    /**
     * A body value with its `readOnly` members dropped.
     *
     * @param array<string, mixed> $schema
     */
    public function value(mixed $value, array $schema): mixed
    {
        return $this->schemas->value($value, $schema, SchemaDirection::Request);
    }
}
