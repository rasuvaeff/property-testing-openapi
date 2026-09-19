<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * The document uses a generation or serialization feature outside the
 * currently implemented support matrix. A refusal over a schema names the
 * operation and the parameter or body it was compiling once that is known
 * ({@see inOperation()}), so a refusal over a forty-operation document is
 * not a search.
 *
 * @api
 */
final class UnsupportedGeneration extends \InvalidArgumentException implements OpenApiPropertyTestingException
{
    private const string PREFIX = 'Unsupported OpenAPI schema generation';

    private ?string $reason = null;

    public static function forSchema(string $reason): self
    {
        $refusal = new self(sprintf('%s: %s', self::PREFIX, $reason));
        $refusal->reason = $reason;

        return $refusal;
    }

    /**
     * The same refusal, placed: `Unsupported OpenAPI schema generation for
     * operation "pets.list", query parameter "limit": minLength exceeds
     * maxLength`. A refusal that did not come from {@see forSchema()} already
     * names what it refuses and is returned as is.
     *
     * @param non-empty-string $subject what was being compiled — `query
     *        parameter "limit"`, `request body "application/json"`,
     *        `response "200" header "X-Total"`
     */
    public function inOperation(string $operationKey, string $subject): self
    {
        if ($this->reason === null) {
            return $this;
        }
        $placed = new self(sprintf('%s for operation "%s", %s: %s', self::PREFIX, $operationKey, $subject, $this->reason), 0, $this);
        $placed->reason = $this->reason;

        return $placed;
    }
}
