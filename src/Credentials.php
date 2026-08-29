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
    /** @var array<string, list<string>> */
    public array $headers;

    /** @var array<string, list<string>> */
    public array $query;

    /** @var array<string, list<string>> */
    public array $cookies;

    /** @var list<string> */
    public array $secretFields;

    /**
     * @param array<string, string|list<string>> $headers
     * @param array<string, string|list<string>> $query
     * @param array<string, string|list<string>> $cookies
     * @param list<string> $secretFields
     */
    public function __construct(
        array $headers = [],
        array $query = [],
        array $cookies = [],
        array $secretFields = [],
    ) {
        $this->headers = $this->normalizeMap($headers, 'headers');
        $this->query = $this->normalizeMap($query, 'query');
        $this->cookies = $this->normalizeMap($cookies, 'cookies');
        $this->assertSecretFields($secretFields);
        $this->secretFields = $secretFields;
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

    /**
     * @param array<array-key, mixed> $map
     *
     * @return array<string, list<string>>
     */
    private function normalizeMap(array $map, string $label): array
    {
        $normalized = [];
        foreach ($map as $name => $values) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException(sprintf('Credential %s must map names to value lists', $label));
            }
            if (is_string($values)) {
                $normalized[$name] = [$values];

                continue;
            }
            if (!is_array($values) || !array_is_list($values)) {
                throw new \InvalidArgumentException(sprintf('Credential %s must map names to value lists', $label));
            }
            foreach (array_keys($values) as $index) {
                if (!is_string($values[$index])) {
                    throw new \InvalidArgumentException(sprintf('Credential %s values must be strings', $label));
                }
            }
            /** @var list<string> $values */
            $normalized[$name] = $values;
        }

        return $normalized;
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
