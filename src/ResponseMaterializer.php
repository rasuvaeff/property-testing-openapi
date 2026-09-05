<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;

/**
 * Materializes a data-only response case as a PSR-7 response immediately
 * before it is handed to the client under test: status, headers serialized
 * with the `simple` style like request headers (read as sent, a list
 * value joined with commas), and a JSON body encoded against the declared
 * schema with its `Content-Type`.
 *
 * @api
 *
 * @psalm-import-type ResponseCaseData from ResponseCaseArbitrary
 */
final readonly class ResponseMaterializer
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private JsonBodyEncoder $json = new JsonBodyEncoder(),
        private ParameterSerializer $parameters = new ParameterSerializer(),
    ) {}

    /** @param ResponseCaseData $case */
    public function materialize(Operation $operation, array $case): ResponseInterface
    {
        if ($case['operationKey'] !== $operation->key) {
            throw new \InvalidArgumentException(sprintf('Response case targets "%s", not "%s"', $case['operationKey'], $operation->key));
        }
        $response = $this->responses->createResponse($case['status']);
        foreach ($case['headers'] as $name => $value) {
            // A header field value is not percent-encoded, and the validator
            // reads it as sent (openapi-contract#66) — on this side of the
            // exchange for exactly the same reason as on the request side.
            $wire = $this->parameters->serialize(
                name: $name,
                value: $value,
                style: 'simple',
                explode: false,
                percentEncoded: false,
            );
            ParameterSerializer::assertTransmittableHeader($name, $wire);
            $response = $response->withHeader($name, $wire);
        }
        $body = $case['body'];
        if ($body === null) {
            return $response;
        }
        if ($body['encoding'] === 'raw') {
            $raw = $body['value'];
            if (!is_string($raw)) {
                throw new UnsupportedGeneration('Raw response body value must be a string');
            }
            $payload = $raw;
        } else {
            $payload = $this->json->encode($body['value'], $this->bodySchema($operation, $case['status'], $body['mediaType'], $case['misuse']));
        }

        return $response
            ->withHeader('Content-Type', $body['mediaType'])
            ->withBody($this->streams->createStream($payload));
    }

    /**
     * A media-type misuse deliberately carries an undeclared Content-Type; the
     * body is still encoded with the declared JSON schema so the media type is
     * the only deviation.
     *
     * @param null|array{kind: non-empty-string, location: 'status'|'header'|'body', name: string} $misuse
     * @return array<string, mixed>
     */
    private function bodySchema(Operation $operation, int $status, string $mediaType, ?array $misuse): array
    {
        $selected = $operation->responseFor($status)['definition'] ?? [];
        $content = isset($selected['content']) && is_array($selected['content']) ? $selected['content'] : [];
        $definition = isset($content[$mediaType]) && is_array($content[$mediaType]) ? $content[$mediaType] : null;
        if ($definition === null && $misuse !== null && in_array($misuse['kind'], ['media-type', 'undeclared-status'], strict: true)) {
            $definition = $this->firstJsonDefinition($content) ?? $this->firstJsonDefinition($this->anyContent($operation));
        }
        if ($definition === null) {
            return [];
        }

        return $this->json->schemaObject($definition['schema'] ?? [], 'Response JSON schema must be an object');
    }

    /**
     * @param array<array-key, mixed> $content
     * @return array<array-key, mixed>|null
     */
    private function firstJsonDefinition(array $content): ?array
    {
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !is_array($definition)) {
                continue;
            }
            if (MediaType::isJson($mediaType)) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<array-key, mixed> */
    private function anyContent(Operation $operation): array
    {
        /** @var mixed $definition */
        foreach ($operation->responses as $definition) {
            if (is_array($definition) && is_array($definition['content'] ?? null)) {
                /** @var array<array-key, mixed> $content */
                $content = $definition['content'];

                return $content;
            }
        }

        return [];
    }
}
