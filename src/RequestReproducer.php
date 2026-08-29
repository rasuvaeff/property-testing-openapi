<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;

/**
 * Renders one data-only case as a redacted curl command.
 *
 * Credentials are never applied: the reproducer materializes the case alone,
 * so provider secrets cannot leak by construction. The policy additionally
 * redacts user-declared case fields, and a small default header set is
 * redacted defensively.
 *
 * @api
 */
final readonly class RequestReproducer
{
    private const array DEFAULT_REDACTED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    private const string REDACTED = '[redacted]';

    private const int MAX_BODY_BYTES = 2048;

    public function __construct(
        private RequestMaterializer $materializer,
    ) {}

    /**
     * @param array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
     *     misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string},
     * } $case
     */
    public function curl(Operation $operation, array $case, RedactionPolicy $policy = new RedactionPolicy()): string
    {
        $case = $this->redactCase($case, $policy);
        $request = $this->materializer->materialize($operation, $case);

        $redactedHeaders = array_merge(self::DEFAULT_REDACTED_HEADERS, array_map(strtolower(...), $policy->headers));
        $parts = ['curl', '-X', $request->getMethod(), $this->quote((string) $request->getUri())];
        foreach (array_keys($request->getHeaders()) as $name) {
            $name = (string) $name;
            $value = in_array(strtolower($name), $redactedHeaders, strict: true) ? self::REDACTED : $request->getHeaderLine($name);
            $parts[] = '-H';
            $parts[] = $this->quote($name . ': ' . $value);
        }
        $body = (string) $request->getBody();
        if ($body !== '') {
            $parts[] = '--data';
            $parts[] = $this->quote($this->truncate($body));
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
     *     misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string},
     * } $case
     * @return array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
     *     misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string},
     * }
     */
    private function redactCase(array $case, RedactionPolicy $policy): array
    {
        foreach ($policy->queryParameters as $name) {
            if (array_key_exists($name, $case['query'])) {
                $case['query'][$name] = self::REDACTED;
            }
        }
        foreach ($policy->cookies as $name) {
            if (array_key_exists($name, $case['cookies'])) {
                $case['cookies'][$name] = self::REDACTED;
            }
        }
        $body = $case['body'];
        if ($body !== null && $body['encoding'] === 'json' && array_key_exists('value', $body) && is_array($body['value'])) {
            $value = $body['value'];
            foreach ($policy->bodyPaths as $path) {
                $this->redactBodyPath($value, explode('.', $path));
            }
            $body['value'] = $value;
            $case['body'] = $body;
        }

        return $case;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $segments
     */
    private function redactBodyPath(array &$value, array $segments): void
    {
        $key = $segments[0];
        if (!array_key_exists($key, $value)) {
            return;
        }
        if (count($segments) === 1) {
            $value[$key] = self::REDACTED;

            return;
        }
        if (is_array($value[$key])) {
            /** @var array<array-key, mixed> $nested */
            $nested = $value[$key];
            $this->redactBodyPath($nested, array_slice($segments, 1));
            $value[$key] = $nested;
        }
    }

    /**
     * Byte-bounded body preview that never cuts a UTF-8 sequence in half.
     */
    private function truncate(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }
        $cut = substr($body, 0, self::MAX_BODY_BYTES);
        while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0b1100_0000) === 0b1000_0000) {
            $cut = substr($cut, 0, -1);
        }
        if ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0b1100_0000) === 0b1100_0000) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . '...[truncated]';
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
