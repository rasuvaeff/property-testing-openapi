<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * A case handed to the package does not have the shape the package writes:
 * a key is missing, a body value has the wrong type for its encoding, a
 * part is not base64, the case targets another operation. This is a caller
 * error over hand-written or edited case data, never a limitation of what
 * the document declares — that one is {@see UnsupportedGeneration}.
 *
 * @api
 */
final class InvalidCase extends \InvalidArgumentException implements OpenApiPropertyTestingException
{
    public static function missingKey(string $key): self
    {
        return new self(sprintf('Case is missing the "%s" key', $key));
    }
}
