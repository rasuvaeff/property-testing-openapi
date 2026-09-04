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
        Assert::string($curl)->contains("-H 'Cookie: session=%5Bredacted%5D'");
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

    public function rejectsAListWithNonStringRedactionName(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new RedactionPolicy(headers: [42]);
    }

    public function redactsDefaultSensitiveHeadersCaseInsensitively(): void
    {
        $operation = new Operation(
            key: 'headers',
            operationId: 'headers',
            method: 'GET',
            path: '/headers',
            parameters: [
                ['name' => 'Authorization', 'in' => 'header', 'required' => false, 'style' => 'simple', 'explode' => false, 'allowReserved' => false, 'schema' => ['type' => 'string']],
            ],
        );
        $curl = $this->reproducer()->curl($operation, [
            'operationKey' => 'headers', 'path' => [], 'query' => [],
            'headers' => ['Authorization' => 'secret'], 'cookies' => [], 'body' => null, 'misuse' => null,
        ]);

        Assert::string($curl)->contains("-H 'Authorization: [redacted]'");
        Assert::false(str_contains($curl, 'secret'));
    }

    public function ignoresMissingAndNonJsonBodyRedactionPaths(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['owner' => 'Milo']], 'misuse' => null,
        ], new RedactionPolicy(bodyPaths: ['missing.child', 'owner.child']));

        Assert::string($curl)->contains('"owner":"Milo"');
    }

    /**
     * `bodyPaths` used to apply to JSON bodies only, so a declared secret in a
     * form or multipart body was a silent no-op — in a feature whose only job
     * is to keep a secret out of the reproducer.
     */
    public function redactsBodyPathsInEveryBodyEncoding(): void
    {
        $operation = $this->bodyOperation();
        $policy = new RedactionPolicy(bodyPaths: ['password']);

        $form = $this->reproducer()->curl($operation, [
            'operationKey' => 'bodies.create', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/x-www-form-urlencoded', 'encoding' => 'form', 'value' => ['user' => 'ada', 'password' => 'hunter2']],
            'misuse' => null,
        ], $policy);

        Assert::false(str_contains($form, 'hunter2'));
        Assert::string($form)->contains('user=ada')->contains('password=%5Bredacted%5D');

        $multipart = $this->reproducer()->curl($operation, [
            'operationKey' => 'bodies.create', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => [
                'mediaType' => 'multipart/form-data', 'encoding' => 'multipart', 'boundary' => 'X',
                'parts' => [
                    ['name' => 'user', 'value' => 'ada', 'encoding' => 'text', 'contentType' => 'text/plain', 'headers' => []],
                    ['name' => 'password', 'value' => base64_encode('hunter2'), 'encoding' => 'base64', 'contentType' => 'application/octet-stream', 'headers' => []],
                ],
            ],
            'misuse' => null,
        ], $policy);

        Assert::false(str_contains($multipart, 'hunter2'));
        Assert::false(str_contains($multipart, base64_encode('hunter2')));
        Assert::string($multipart)->contains('ada')->contains('[redacted]');
    }

    /**
     * A redacted value keeps its shape. Replacing an object or a list with a
     * bare string made the serializer refuse it — `deepObject` requires an
     * object, the delimited styles a list — so `reproduce()` threw and the
     * caller printed "(no reproducer: …)", removing the reproducer for exactly
     * the failure it is needed on.
     */
    public function redactsAParameterWithoutLosingItsShape(): void
    {
        $curl = $this->reproducer()->curl($this->shapedOperation(), [
            'operationKey' => 'shapes.get', 'path' => [], 'headers' => [], 'cookies' => [],
            'query' => ['filter' => ['token' => 's3cret', 'kind' => 'cat'], 'tags' => ['alpha', 'beta']],
            'body' => null, 'misuse' => null,
        ], new RedactionPolicy(queryParameters: ['filter', 'tags']));

        Assert::false(str_contains($curl, 's3cret'));
        Assert::false(str_contains($curl, 'alpha'));
        // The whole query, not a substring: a list redacted member-by-member
        // would still *contain* one marker while carrying as many as the
        // original had, which is a value the policy never promised to hide.
        Assert::string($curl)->contains(
            "curl -X GET '/shapes?filter%5Btoken%5D=%5Bredacted%5D&filter%5Bkind%5D=%5Bredacted%5D&tags=%5Bredacted%5D'",
        );
    }

    /**
     * The three ways `redactCase()` can be handed nothing to do, each pinned
     * so the early exit stays an exit and not a silent skip of the work.
     */
    public function leavesTheCaseAloneWhenThereIsNothingToRedact(): void
    {
        $operation = $this->bodyOperation();
        $case = [
            'operationKey' => 'bodies.create', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/x-www-form-urlencoded', 'encoding' => 'form', 'value' => ['user' => 'ada']],
            'misuse' => null,
        ];

        // A policy with no body paths does not touch the body.
        Assert::string($this->reproducer()->curl($operation, $case, new RedactionPolicy(headers: ['X-Trace'])))
            ->contains('user=ada');
        // A body path with no body is not an error.
        Assert::string($this->reproducer()->curl($operation, [...$case, 'body' => null], new RedactionPolicy(bodyPaths: ['user'])))
            ->contains("curl -X POST '/bodies'");
        // A raw body carries no named members to redact.
        Assert::string($this->reproducer()->curl($operation, [...$case, 'body' => ['mediaType' => 'text/plain', 'encoding' => 'raw', 'value' => 'ada']], new RedactionPolicy(bodyPaths: ['user'])))
            ->contains('ada');
    }

    /**
     * The policy names a cookie and that cookie is redacted; the rest of the
     * header stays readable. Redacting the whole `Cookie` header made the
     * option inert — a policy naming a cookie and one that did not produced
     * byte-identical output — and it was defending case data rather than a
     * credential, since the reproducer never applies credentials at all.
     */
    public function redactsOnlyTheCookiesThePolicyNames(): void
    {
        $case = [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [],
            'cookies' => ['session' => 'abc', 'theme' => 'dark'],
            'body' => null, 'misuse' => null,
        ];

        $redacted = $this->reproducer()->curl($this->cookieOperation(), $case, new RedactionPolicy(cookies: ['session']));
        Assert::false(str_contains($redacted, 'abc'));
        Assert::string($redacted)->contains("-H 'Cookie: session=%5Bredacted%5D; theme=dark'");

        // Without a policy the case's own data is readable, which is the point
        // of a reproducer.
        $plain = $this->reproducer()->curl($this->cookieOperation(), $case);
        Assert::string($plain)->contains("-H 'Cookie: session=abc; theme=dark'");
    }

    private function cookieOperation(): Operation
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['post' => [
                'operationId' => 'pets.update',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'session', 'in' => 'cookie', 'schema' => ['type' => 'string']],
                    ['name' => 'theme', 'in' => 'cookie', 'schema' => ['type' => 'string']],
                ],
                'responses' => ['204' => []],
            ]]],
        ])->operation('pets.update');
    }

    private function bodyOperation(): Operation
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/bodies' => ['post' => [
                'operationId' => 'bodies.create',
                'requestBody' => ['content' => [
                    'application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'properties' => ['user' => ['type' => 'string'], 'password' => ['type' => 'string']]]],
                    'multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['user' => ['type' => 'string'], 'password' => ['type' => 'string']]]],
                ]],
                'responses' => ['204' => []],
            ]]],
        ])->operation('bodies.create');
    }

    private function shapedOperation(): Operation
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/shapes' => ['get' => [
                'operationId' => 'shapes.get',
                'parameters' => [
                    ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'explode' => true,
                        'schema' => ['type' => 'object', 'properties' => ['token' => ['type' => 'string'], 'kind' => ['type' => 'string']]]],
                    ['name' => 'tags', 'in' => 'query', 'style' => 'spaceDelimited', 'explode' => false,
                        'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                ],
                'responses' => ['204' => []],
            ]]],
        ])->operation('shapes.get');
    }

    public function leavesBodyAtOrBelowThePreviewLimitUnchanged(): void
    {
        $body = str_repeat('x', 2048);
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => $body], 'misuse' => null,
        ]);

        Assert::false(str_contains($curl, '...[truncated]'));
        Assert::string($curl)->contains($body);
    }

    public function truncatesAtTheExactByteBudgetBeforeAMultibyteLead(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => str_repeat('a', 2047) . '😀'], 'misuse' => null,
        ]);

        Assert::string($curl)->contains("--data '" . str_repeat('a', 2047) . "...[truncated]'");
    }

    public function dropsTrailingContinuationBytesBeforeTheLeadByte(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => str_repeat('a', 2045) . '😀'], 'misuse' => null,
        ]);

        Assert::string($curl)->contains("--data '" . str_repeat('a', 2045) . "...[truncated]'");
    }

    public function keepsACompleteTrailingAsciiByteWhenTruncating(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => str_repeat('a', 2049)], 'misuse' => null,
        ]);

        Assert::string($curl)->contains("--data '" . str_repeat('a', 2048) . "...[truncated]'");
    }

    public function stripsOnlyTheDanglingContinuationByteFromAnInvalidBody(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => str_repeat('a', 2047) . "\x80a"], 'misuse' => null,
        ]);

        Assert::string($curl)->contains("--data '" . str_repeat('a', 2047) . "...[truncated]'");
    }

    public function addsNothingWhenARedactionPathIsAbsent(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['a' => 'x']], 'misuse' => null,
        ], new RedactionPolicy(bodyPaths: ['missing']));

        Assert::string($curl)->contains('--data \'{"a":"x"}\'');
        Assert::false(str_contains($curl, 'missing'));
    }

    public function skipsBodyRedactionForANonArrayJsonValue(): void
    {
        $curl = $this->reproducer()->curl($this->operation(), [
            'operationKey' => 'pets.update', 'path' => ['id' => '7'], 'query' => [], 'headers' => [], 'cookies' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 'hello'], 'misuse' => null,
        ], new RedactionPolicy(bodyPaths: ['a']));

        Assert::string($curl)->contains('--data \'"hello"\'');
    }

    public function rejectsAnInvalidQueryParameterRedactionList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new RedactionPolicy(queryParameters: ['']);
    }

    public function rejectsAnInvalidCookieRedactionList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new RedactionPolicy(cookies: ['']);
    }

    public function rejectsAnInvalidBodyPathRedactionList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new RedactionPolicy(bodyPaths: ['']);
    }

    public function rejectsARedactionMapInsteadOfAList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        /** @var list<non-empty-string> $headers */
        $headers = ['name' => 'value'];
        new RedactionPolicy(headers: $headers);
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


    public function rendersTheEffectiveServerUri(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://api.example.com/v1']],
            'paths' => ['/pets/{id}' => ['get' => [
                'operationId' => 'pets.get',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => ['200' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $curl = (new RequestReproducer(new RequestMaterializer($factory, $factory)))->curl(
            $contract->operation('pets.get'),
            ['operationKey' => 'pets.get', 'path' => ['id' => '42'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null],
        );

        Assert::string($curl)->contains('https://api.example.com/v1/pets/42');
    }
}
