<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestInterface;

/**
 * Secret material kept outside data-only generated request cases.
 *
 * @api
 */
final readonly class Credentials
{
    /**
     * @param array<string, list<string>> $headers
     * @param array<string, list<string>> $query
     * @param array<string, list<string>> $cookies
     * @param list<string> $secretFields
     */
    public function __construct(
        public array $headers = [],
        public array $query = [],
        public array $cookies = [],
        public array $secretFields = [],
    ) {
        $this->assertMap($headers, 'headers');
        $this->assertMap($query, 'query');
        $this->assertMap($cookies, 'cookies');
        $this->assertSecretFields($secretFields);
    }

    public function apply(RequestInterface $request): RequestInterface
    {
        foreach ($this->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }
        if ($this->query !== []) {
            $parts = [];
            foreach ($this->query as $name => $values) {
                foreach ($values as $value) {
                    $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
                }
            }
            $query = $request->getUri()->getQuery();
            $credentialQuery = implode('&', $parts);
            $combinedQuery = $query === '' || $credentialQuery === '' ? $query . $credentialQuery : $query . '&' . $credentialQuery;
            $request = $request->withUri($request->getUri()->withQuery($combinedQuery));
        }
        if ($this->cookies !== []) {
            $cookies = [];
            foreach ($this->cookies as $name => $values) {
                foreach ($values as $value) {
                    $cookies[] = rawurlencode($name) . '=' . rawurlencode($value);
                }
            }
            $existing = $request->getHeaderLine('Cookie');
            $credentialCookies = implode('; ', $cookies);
            $combinedCookies = $existing === '' || $credentialCookies === '' ? $existing . $credentialCookies : $existing . '; ' . $credentialCookies;
            $request = $request->withHeader('Cookie', $combinedCookies);
        }

        return $request;
    }

    private function assertMap(mixed $map, string $label): void
    {
        if (!is_array($map)) {
            throw new \InvalidArgumentException(sprintf('Credential %s must map names to value lists', $label));
        }
        foreach ($map as $name => $values) {
            if (!is_string($name) || $name === '' || !is_array($values) || !array_is_list($values)) {
                throw new \InvalidArgumentException(sprintf('Credential %s must map names to value lists', $label));
            }
            foreach (array_keys($values) as $index) {
                if (!is_string($values[$index])) {
                    throw new \InvalidArgumentException(sprintf('Credential %s values must be strings', $label));
                }
            }
        }
    }

    private function assertSecretFields(mixed $fields): void
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw new \InvalidArgumentException('Credential secret fields must be a list');
        }
        foreach ($fields as $field) {
            if (!is_string($field) || $field === '') {
                throw new \InvalidArgumentException('Credential secret fields must be non-empty strings');
            }
        }
    }
}
