<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RequestSchemas;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Derives named, corpus-safe valid request cases from the document's own
 * `example`/`examples` declarations so they run before the random phase.
 *
 * Every part of an operation (each parameter, the request body) contributes
 * its named examples (`examples` map, Example Objects with a `value`) and one
 * unnamed example (`example`, then the Schema Object's `example`, then the
 * first entry of the Schema Object's `examples` list). One case is produced
 * per distinct example name across all parts, plus a case named `example`
 * when any part declares an unnamed example. A part without an example under
 * a given name falls back to its unnamed example, then to the value drawn by
 * the deterministic base case (fixed seed, so the set is identical under any
 * `PROPERTY_SEED`).
 *
 * The cases are not validated here: an example that violates its own schema
 * surfaces through the same pre-transport validation as a generated case, as
 * a diagnosable document defect rather than a silently skipped example.
 * Example Objects with only `externalValue`, and multipart bodies, contribute
 * nothing. A body example shared between directions loses its `readOnly`
 * members, as the request direction of its schema does. Credentials are
 * never part of an example case.
 *
 * @internal Reach it through {@see ContractSuite::exampleCases()}.
 *
 * @psalm-import-type CaseData from ContractSuite
 */
final readonly class DocumentExamples
{
    public const string UNNAMED = 'example';

    private const int BASE_SEED = 0;

    private RequestSchemas $requestSchemas;

    public function __construct(
        private RequestCaseArbitrary $cases = new RequestCaseArbitrary(),
    ) {
        $this->requestSchemas = new RequestSchemas();
    }

    /**
     * @return array<string, CaseData> keyed by example name, unnamed first,
     *         then named examples in document order
     */
    public function forOperation(Operation $operation): array
    {
        $parts = $this->parts($operation);
        if ($parts === []) {
            return [];
        }
        /** @var array<array-key, true> $names */
        $names = [];
        $hasUnnamed = false;
        foreach ($parts as $part) {
            $hasUnnamed = $hasUnnamed || $part['unnamed'] !== null;
            foreach (array_keys($part['named']) as $name) {
                $names[$name] = true;
            }
        }
        /** @var CaseData $base */
        $base = $this->cases->forOperation($operation)->generate(new Random(self::BASE_SEED))->value;
        $result = [];
        if ($hasUnnamed) {
            $result[self::UNNAMED] = $this->apply($base, $parts, null);
        }
        foreach (array_keys($names) as $name) {
            $result[(string) $name] = $this->apply($base, $parts, (string) $name);
        }

        return $result;
    }

    /**
     * @return list<array{location: 'path'|'query'|'headers'|'cookies'|'body', name: string, schema: array<string, mixed>, mediaType: string, encoding: 'json'|'form', unnamed: null|array{value: mixed}, named: array<string, array{value: mixed}>}>
     */
    private function parts(Operation $operation): array
    {
        $parts = [];
        $body = $this->bodyPart($operation);
        if ($body !== null) {
            $parts[] = $body;
        }
        foreach ($operation->parameters as $parameter) {
            $unnamed = $this->unnamed($parameter, $parameter['schema']);
            $named = $this->named($parameter['examples'] ?? null, sprintf('parameter "%s"', $parameter['name']));
            if ($unnamed === null && $named === []) {
                continue;
            }
            $parts[] = [
                'location' => match ($parameter['in']) {
                    'path' => 'path',
                    'query' => 'query',
                    'header' => 'headers',
                    'cookie' => 'cookies',
                },
                'name' => $parameter['name'],
                'schema' => $parameter['schema'],
                'mediaType' => '',
                'encoding' => 'json',
                'unnamed' => $unnamed,
                'named' => $named,
            ];
        }

        return $parts;
    }

    /**
     * @return null|array{location: 'body', name: string, schema: array<string, mixed>, mediaType: string, encoding: 'json'|'form', unnamed: null|array{value: mixed}, named: array<string, array{value: mixed}>}
     */
    private function bodyPart(Operation $operation): ?array
    {
        $content = isset($operation->requestBody['content']) && is_array($operation->requestBody['content']) ? $operation->requestBody['content'] : [];
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !is_array($definition)) {
                continue;
            }
            $normalized = strtolower(trim(explode(';', $mediaType, 2)[0]));
            $encoding = match (true) {
                $normalized === 'application/json', str_ends_with($normalized, '+json') => 'json',
                $normalized === 'application/x-www-form-urlencoded' => 'form',
                default => null,
            };
            // A media type this phase cannot encode, or one carrying no
            // example, says nothing about the entries after it: `content` is a
            // map, and multipart before JSON is an ordering, not a verdict.
            if ($encoding === null) {
                continue;
            }
            $schema = $definition['schema'] ?? [];
            if (!is_array($schema) || array_is_list($schema)) {
                continue;
            }
            /** @var array<string, mixed> $schema */
            $unnamed = $this->unnamed($definition, $schema);
            $named = $this->named($definition['examples'] ?? null, sprintf('request body "%s"', $mediaType));
            if ($unnamed === null && $named === []) {
                continue;
            }

            return [
                'location' => 'body',
                'name' => $mediaType,
                'schema' => $schema,
                'mediaType' => $mediaType,
                'encoding' => $encoding,
                'unnamed' => $unnamed,
                'named' => $named,
            ];
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $owner
     * @param array<string, mixed> $schema
     * @return null|array{value: mixed}
     */
    private function unnamed(array $owner, array $schema): ?array
    {
        if (array_key_exists('example', $owner)) {
            return ['value' => $owner['example']];
        }
        if (array_key_exists('example', $schema)) {
            return ['value' => $schema['example']];
        }
        if (isset($schema['examples']) && is_array($schema['examples']) && array_is_list($schema['examples']) && $schema['examples'] !== []) {
            return ['value' => $schema['examples'][0]];
        }

        return null;
    }

    /** @return array<string, array{value: mixed}> */
    private function named(mixed $examples, string $owner): array
    {
        if ($examples === null) {
            return [];
        }
        if (!is_array($examples) || array_is_list($examples)) {
            throw new UnsupportedGeneration(sprintf('Examples of %s must be a map of Example Objects', $owner));
        }
        $result = [];
        foreach ($examples as $name => $example) {
            if (!is_array($example)) {
                throw new UnsupportedGeneration(sprintf('Example "%s" of %s must be an Example Object', (string) $name, $owner));
            }
            if (!array_key_exists('value', $example)) {
                continue;
            }
            $result[(string) $name] = ['value' => $example['value']];
        }

        return $result;
    }

    /**
     * @param CaseData $base
     * @param list<array{location: 'path'|'query'|'headers'|'cookies'|'body', name: string, schema: array<string, mixed>, mediaType: string, encoding: 'json'|'form', unnamed: null|array{value: mixed}, named: array<string, array{value: mixed}>}> $parts
     * @return CaseData
     */
    private function apply(array $base, array $parts, ?string $name): array
    {
        $case = $base;
        foreach ($parts as $part) {
            $example = $name === null ? $part['unnamed'] : ($part['named'][$name] ?? $part['unnamed']);
            if ($example === null) {
                continue;
            }
            if ($part['location'] === 'body') {
                $case['body'] = ['mediaType' => $part['mediaType'], 'encoding' => $part['encoding'], 'value' => $this->requestSchemas->value($example['value'], $part['schema'])];

                continue;
            }
            $case[$part['location']] = array_merge($case[$part['location']], [$part['name'] => $this->wireValue($example['value'], $part['schema'], $part['name'])]);
        }

        return $case;
    }

    /**
     * @param array<string, mixed> $schema
     * @return string|list<string>|array<string, string>
     */
    private function wireValue(mixed $value, array $schema, string $parameter): string|array
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn(mixed $item): string => $this->scalar($item, $parameter), $value);
            }
            if (($schema['type'] ?? null) === 'array') {
                throw new UnsupportedGeneration(sprintf('Example of parameter "%s" must be a list for an array schema', $parameter));
            }
            $result = [];
            /** @var mixed $item */
            foreach ($value as $key => $item) {
                $result[(string) $key] = $this->scalar($item, $parameter);
            }

            return $result;
        }

        return $this->scalar($value, $parameter);
    }

    private function scalar(mixed $value, string $parameter): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => throw new UnsupportedGeneration(sprintf('Example of parameter "%s" must be a scalar, a list of scalars, or a map of scalars', $parameter)),
        };
    }
}
