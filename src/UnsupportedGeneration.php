<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * The contract uses a generation or serialization feature outside the
 * currently implemented support matrix.
 *
 * @api
 */
final class UnsupportedGeneration extends \InvalidArgumentException
{
    public static function forSchema(string $reason): self
    {
        return new self(sprintf('Unsupported OpenAPI schema generation: %s', $reason));
    }
}
