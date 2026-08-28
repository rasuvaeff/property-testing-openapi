<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * One OpenAPI security requirement alternative.
 *
 * @api
 */
final readonly class SecurityRequirement
{
    /**
     * @param array<string, list<string>> $schemes
     */
    public function __construct(public array $schemes)
    {
        foreach ($schemes as $name => $scopes) {
            if ($name === '' || !array_is_list($scopes)) {
                throw new \InvalidArgumentException('Security requirement schemes must map names to scope lists');
            }
        }
    }

    public function requires(string $scheme): bool
    {
        return array_key_exists($scheme, $this->schemes);
    }
}
