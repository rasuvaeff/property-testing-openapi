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
        $this->assertSchemes($schemes);
    }

    public function requires(string $scheme): bool
    {
        return array_key_exists($scheme, $this->schemes);
    }

    private function assertSchemes(mixed $schemes): void
    {
        if (!is_array($schemes)) {
            throw new \InvalidArgumentException('Security requirement schemes must map names to scope lists');
        }
        foreach ($schemes as $name => $scopes) {
            if (!is_string($name) || $name === '' || !is_array($scopes) || !array_is_list($scopes)) {
                throw new \InvalidArgumentException('Security requirement schemes must map names to scope lists');
            }
            foreach (array_keys($scopes) as $index) {
                if (!is_string($scopes[$index])) {
                    throw new \InvalidArgumentException('Security requirement scopes must contain strings');
                }
            }
        }
    }
}
