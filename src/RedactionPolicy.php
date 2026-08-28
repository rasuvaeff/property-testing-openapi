<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Names the request parts a reproducer must replace with a redaction marker
 * on top of the built-in defaults.
 *
 * @api
 */
final readonly class RedactionPolicy
{
    /**
     * @param list<non-empty-string> $headers header names, case-insensitive
     * @param list<non-empty-string> $queryParameters declared query parameter names
     * @param list<non-empty-string> $cookies declared cookie parameter names
     * @param list<non-empty-string> $bodyPaths dot-separated JSON object paths
     */
    public function __construct(
        public array $headers = [],
        public array $queryParameters = [],
        public array $cookies = [],
        public array $bodyPaths = [],
    ) {
        $this->assertNames($headers, 'headers');
        $this->assertNames($queryParameters, 'query parameters');
        $this->assertNames($cookies, 'cookies');
        $this->assertNames($bodyPaths, 'body paths');
    }

    private function assertNames(mixed $names, string $label): void
    {
        if (!is_array($names) || !array_is_list($names)) {
            throw new \InvalidArgumentException(sprintf('Redacted %s must be a list of non-empty strings', $label));
        }
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException(sprintf('Redacted %s must be a list of non-empty strings', $label));
            }
        }
    }
}
