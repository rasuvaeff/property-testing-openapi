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
        foreach ($secretFields as $field) {
            if ($field === '') {
                throw new \InvalidArgumentException('Credential secret fields must be non-empty strings');
            }
        }
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

    /** @param array<string, list<string>> $map */
    private function assertMap(array $map, string $label): void
    {
        foreach ($map as $name => $values) {
            if ($name === '' || !array_is_list($values)) {
                throw new \InvalidArgumentException(sprintf('Credential %s must map names to value lists', $label));
            }
        }
    }
}
