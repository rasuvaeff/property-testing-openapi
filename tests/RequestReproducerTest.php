<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\RedactionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\RequestReproducer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RequestReproducer::class)]
#[Covers(RedactionPolicy::class)]
final class RequestReproducerTest
{
    public function rendersACurlCommandWithMethodUriHeadersAndBody(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update',
            'path' => ['id' => '7'],
            'query' => ['token' => 'plain'],
            'headers' => ['X-Tenant' => 'acme'],
            'cookies' => ['session' => 'abc'],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['name' => "O'Hara", 'secret' => 'hunter2']],
            'misuse' => null,
        ]);

        Assert::string($curl)->contains("curl -X POST '/pets/7?token=plain'");
        Assert::string($curl)->contains("-H 'X-Tenant: acme'");
        Assert::string($curl)->contains("-H 'Content-Type: application/json'");
        Assert::string($curl)->contains('O' . "'\\''" . 'Hara');
    }

    public function redactsDefaultHeadersAndPolicyFields(): void
    {
        $policy = new RedactionPolicy(
            headers: ['X-Tenant'],
            queryParameters: ['token'],
            cookies: ['session'],
            bodyPaths: ['secret'],
        );
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update',
            'path' => ['id' => '7'],
            'query' => ['token' => 'topsecret'],
            'headers' => ['X-Tenant' => 'acme'],
            'cookies' => ['session' => 'abc'],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['name' => 'Milo', 'secret' => 'hunter2']],
            'misuse' => null,
        ], $policy);

        Assert::string($curl)->contains('token=%5Bredacted%5D');
        Assert::string($curl)->contains("-H 'X-Tenant: [redacted]'");
        Assert::string($curl)->contains("-H 'Cookie: [redacted]'");
        Assert::string($curl)->contains('"secret":"[redacted]"');
        Assert::false(str_contains($curl, 'topsecret'));
        Assert::false(str_contains($curl, 'hunter2'));
        Assert::false(str_contains($curl, 'acme'));
        Assert::false(str_contains($curl, 'session=abc'));
    }

    public function redactsANestedBodyPath(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update',
            'path' => ['id' => '7'],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['owner' => ['card' => '4111', 'name' => 'Milo']]],
            'misuse' => null,
        ], new RedactionPolicy(bodyPaths: ['owner.card']));

        Assert::string($curl)->contains('"card":"[redacted]"');
        Assert::string($curl)->contains('"name":"Milo"');
        Assert::false(str_contains($curl, '4111'));
    }

    public function truncatesAnOversizedBodyWithoutBreakingUtf8(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update',
            'path' => ['id' => '7'],
            'query' => [],
            'headers' => [],
            'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => 'a' . str_repeat('я', 1024)],
            'misuse' => ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'],
        ]);

        Assert::string($curl)->contains('...[truncated]');
        $data = explode("--data '", $curl, 2)[1];
        Assert::true(mb_check_encoding(substr($data, 0, -1), 'UTF-8'));
        Assert::false(str_contains($curl, 'a' . str_repeat('я', 1024)));
    }

    public function rejectsAnInvalidRedactionPolicy(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new RedactionPolicy(headers: ['']);
    }

    private function reproducer(): RequestReproducer
    {
        $factory = new Psr17Factory();

        return new RequestReproducer(new RequestMaterializer($factory, $factory));
    }

    private function operation(): Operation
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'post' => [
                        'operationId' => 'pets.update',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                            ['name' => 'token', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'X-Tenant', 'in' => 'header', 'schema' => ['type' => 'string']],
                            ['name' => 'session', 'in' => 'cookie', 'schema' => ['type' => 'string']],
                        ],
                        'requestBody' => [
                            'content' => ['application/json' => ['schema' => ['type' => 'object']]],
                        ],
                        'responses' => ['204' => []],
                    ],
                ],
            ],
        ])->operation('pets.update');
    }
}
