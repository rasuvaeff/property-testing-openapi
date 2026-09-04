<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;

/**
 * Renders one data-only case as a redacted curl command.
 *
 * Credentials are never applied: the reproducer materializes the case alone,
 * so provider secrets cannot leak by construction. What remains is the case's
 * own generated data, and the policy is how a caller says which of it is
 * sensitive.
 *
 * The default header set is redacted on the name alone, because a header
 * arrives from the case as one opaque string and its name is the only thing
 * left to judge it by. A cookie is different: it reaches the request through
 * `$case['cookies']`, where the policy can name one member and leave the rest
 * readable — so redacting the whole `Cookie` header made
 * {@see RedactionPolicy::$cookies} unobservable, which is to say inert. It is
 * not in the default set for that reason.
 *
 * @internal Reach it through {@see ContractSuite::reproduce()}.
 */
final readonly class RequestReproducer
{
    private const array DEFAULT_REDACTED_HEADERS = ['authorization', 'proxy-authorization', 'set-cookie'];

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
                $case['query'][$name] = $this->redactedLike($case['query'][$name]);
            }
        }
        foreach ($policy->cookies as $name) {
            if (array_key_exists($name, $case['cookies'])) {
                $case['cookies'][$name] = $this->redactedLike($case['cookies'][$name]);
            }
        }
        $body = $case['body'];
        if ($body === null) {
            return $case;
        }
        // One branch per encoding rather than a guard per encoding: the shapes
        // are disjoint, so the second reading of `encoding` only ever repeated
        // what the first already decided.
        $case['body'] = match ($body['encoding']) {
            'json', 'form' => $this->redactBodyValue($body, $policy->bodyPaths),
            'multipart' => $this->redactBodyParts($body, $policy->bodyPaths),
            default => $body,
        };

        return $case;
    }

    /**
     * @param array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed} $body
     * @param list<non-empty-string> $paths
     * @return array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}
     */
    private function redactBodyValue(array $body, array $paths): array
    {
        $value = $body['value'] ?? null;
        if (!is_array($value)) {
            return $body;
        }
        foreach ($paths as $path) {
            $this->redactBodyPath($value, explode('.', $path));
        }
        $body['value'] = $value;

        return $body;
    }

    /**
     * @param array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed} $body
     * @param list<non-empty-string> $paths
     * @return array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}
     */
    private function redactBodyParts(array $body, array $paths): array
    {
        $names = [];
        foreach ($paths as $path) {
            $names[explode('.', $path)[0]] = true;
        }
        $body['parts'] = array_map(static function (array $part) use ($names): array {
            if (!isset($names[$part['name']])) {
                return $part;
            }
            // A redacted part travels as text: the marker is not base64, and
            // claiming it is would make the wire disagree with the value.
            $part['value'] = self::REDACTED;
            $part['encoding'] = 'text';

            return $part;
        }, $body['parts'] ?? []);

        return $body;
    }

    /**
     * A redacted value keeps the shape it had. Replacing an object or a list
     * with a bare string made the serializer refuse it — `deepObject` requires
     * an object, the delimited styles a list — so `reproduce()` threw and the
     * caller printed "(no reproducer: …)". Protecting a secret must not remove
     * the reproducer for the very failure it is needed on.
     */
    /**
     * @param string|list<string>|array<string, string> $value
     * @return string|list<string>|array<string, string>
     */
    private function redactedLike(string|array $value): string|array
    {
        if (is_string($value)) {
            return self::REDACTED;
        }
        if (array_is_list($value)) {
            return $value === [] ? [] : [self::REDACTED];
        }
        /** @var array<string, string> $redacted */
        $redacted = [];
        foreach (array_keys($value) as $key) {
            $redacted = array_replace($redacted, [$key => self::REDACTED]);
        }

        return $redacted;
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
