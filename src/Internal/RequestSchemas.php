<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * The request-direction view of a schema: `readOnly` properties are not
 * part of a request, so they leave `properties` and `required` the way the
 * contract validator drops them before checking a request body.
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
        return $this->schemas->effective($schema, 'readOnly');
    }

    /**
     * A body value with its `readOnly` members dropped.
     *
     * @param array<string, mixed> $schema
     */
    public function value(mixed $value, array $schema): mixed
    {
        return $this->schemas->value($value, $schema, 'readOnly');
    }
}
