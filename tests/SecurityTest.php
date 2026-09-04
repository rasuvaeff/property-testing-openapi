<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsProviderInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\SecurityRequirement;
use Rasuvaeff\PropertyTesting\OpenApi\SecuritySelector;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;

#[Test]
#[Covers(Credentials::class)]
#[Covers(SecurityRequirement::class)]
#[Covers(SecuritySelector::class)]
#[Covers(CredentialsUnavailable::class)]
final class SecurityTest
{
    public function selectsTheFirstSatisfiableAlternative(): void
    {
        $operation = new Operation(
            key: 'pets.list',
            operationId: 'pets.list',
            method: 'GET',
            path: '/pets',
            security: [
                ['oauth' => ['pets:read'], 'tenant' => []],
                ['apiKey' => []],
            ],
        );
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::satisfies(static fn(mixed $requirement): bool => $requirement instanceof SecurityRequirement
            && $requirement->requires('oauth')
            && $requirement->requires('tenant'))))->throws(new CredentialsUnavailable('oauth fixture is unavailable'));
        expect(fn() => $provider->provide(Arg::satisfies(static fn(mixed $requirement): bool => $requirement instanceof SecurityRequirement
            && $requirement->requires('apiKey'))))->returns(new Credentials(headers: ['X-Api-Key' => ['secret']]));

        $selected = (new SecuritySelector())->select($operation, $provider);

        if (!is_array($selected)) {
            throw new \LogicException('Security selector returned no credentials');
        }
        Assert::same($selected['requirement']->schemes, ['apiKey' => []]);
        Assert::same($selected['credentials']->headers, ['X-Api-Key' => ['secret']]);
    }

    public function returnsNullForAnAnonymousOperation(): void
    {
        $operation = new Operation(key: 'health', operationId: 'health', method: 'GET', path: '/health');
        $provider = Understudy::for(CredentialsProviderInterface::class);

        Assert::null((new SecuritySelector())->select($operation, $provider));
        verify(fn() => $provider->provide(Arg::any()), never: true);
    }

    public function selectsAnEmptySecurityRequirementWithoutProviderCall(): void
    {
        $operation = new Operation(
            key: 'health',
            operationId: 'health',
            method: 'GET',
            path: '/health',
            security: [[]],
        );
        $provider = Understudy::for(CredentialsProviderInterface::class);

        $selected = (new SecuritySelector())->select($operation, $provider);

        Assert::same($selected['requirement']->schemes, []);
        Assert::same($selected['credentials']->headers, []);
        verify(fn() => $provider->provide(Arg::any()), never: true);
    }

    /**
     * `security: [{}, {apiKey: []}]` says authentication is optional. Taking
     * the anonymous alternative as soon as it was seen meant the suite never
     * exercised the authenticated path of such an operation at all — half its
     * coverage, lost without a word. An alternative the provider can satisfy
     * is preferred now; the anonymous one is the fallback.
     */
    public function prefersASatisfiableAlternativeOverTheAnonymousOne(): void
    {
        $operation = new Operation(
            key: 'pets.list',
            operationId: 'pets.list',
            method: 'GET',
            path: '/pets',
            security: [[], ['apiKey' => []]],
        );
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::any()))->returns(new Credentials(headers: ['X-Api-Key' => 'k']));

        $selected = (new SecuritySelector())->select($operation, $provider);

        if (!is_array($selected)) {
            throw new \LogicException('Security selector returned no credentials');
        }
        Assert::same(array_keys($selected['requirement']->schemes), ['apiKey']);
        Assert::same($selected['credentials']->headers, ['X-Api-Key' => ['k']]);
    }

    /**
     * The anonymous alternative still answers when nothing else can, which is
     * what makes it a fallback rather than a preference.
     */
    public function fallsBackToTheAnonymousAlternative(): void
    {
        $operation = new Operation(
            key: 'pets.list',
            operationId: 'pets.list',
            method: 'GET',
            path: '/pets',
            security: [[], ['apiKey' => []]],
        );
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::any()))->throws(new CredentialsUnavailable('none'));

        $selected = (new SecuritySelector())->select($operation, $provider);

        if (!is_array($selected)) {
            throw new \LogicException('Security selector returned no credentials');
        }
        Assert::same($selected['requirement']->schemes, []);
        Assert::same($selected['credentials']->headers, []);
    }

    public function failsWhenNoAlternativeCanBeSatisfied(): void
    {
        Expect::exception(CredentialsUnavailable::class);
        $operation = new Operation(
            key: 'pets.list',
            operationId: 'pets.list',
            method: 'GET',
            path: '/pets',
            security: [['oauth' => []]],
        );
        $provider = Understudy::for(CredentialsProviderInterface::class);
        expect(fn() => $provider->provide(Arg::satisfies(static fn(mixed $requirement): bool => $requirement instanceof SecurityRequirement
            && $requirement->requires('oauth'))))->throws(new CredentialsUnavailable('no fixture'));

        (new SecuritySelector())->select($operation, $provider);
    }

    public function appliesHeadersQueryAndCookiesAfterMaterialization(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/pets?existing=1')->withHeader('Cookie', 'locale=en');
        $credentials = new Credentials(
            headers: ['Authorization' => ['Bearer token']],
            query: ['tenant' => ['a b']],
            cookies: ['sid' => ['s;id']],
            secretFields: ['Authorization'],
        );

        $request = $credentials->apply($request);

        Assert::same($request->getHeaderLine('Authorization'), 'Bearer token');
        Assert::same($request->getUri()->getQuery(), 'existing=1&tenant=a%20b');
        Assert::same($request->getHeaderLine('Cookie'), 'locale=en; sid=s%3Bid');
        Assert::same($credentials->secretFields, ['Authorization']);
    }

    public function normalizesPlainCredentialValuesToLists(): void
    {
        $credentials = new Credentials(
            headers: ['Authorization' => 'Bearer token', 'X-Scope' => ['read', 'write']],
            query: ['tenant' => 'public'],
            cookies: ['sid' => 'abc'],
        );

        Assert::same($credentials->headers, [
            'Authorization' => ['Bearer token'],
            'X-Scope' => ['read', 'write'],
        ]);
        Assert::same($credentials->query, ['tenant' => ['public']]);
        Assert::same($credentials->cookies, ['sid' => ['abc']]);
    }

    public function rejectsNonStringCredentialValues(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $headers */
        $headers = ['X-Api-Key' => [42]];
        new Credentials(headers: $headers);
    }

    public function rejectsNonStringSecurityScopes(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $schemes */
        $schemes = ['oauth' => [42]];
        new SecurityRequirement($schemes);
    }

    public function appendsCredentialsToEmptyQueryAndCookieValues(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/pets');
        $credentials = new Credentials(
            query: ['tenant' => ['public']],
            cookies: ['sid' => ['abc']],
        );

        $request = $credentials->apply($request);

        Assert::same($request->getUri()->getQuery(), 'tenant=public');
        Assert::same($request->getHeaderLine('Cookie'), 'sid=abc');
    }

    public function rejectsNonStringQueryValues(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $query */
        $query = ['tenant' => [42]];
        new Credentials(query: $query);
    }

    public function rejectsNonStringCookieValues(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $cookies */
        $cookies = ['sid' => [42]];
        new Credentials(cookies: $cookies);
    }

    public function rejectsNonStringSecretFields(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var list<string> $fields */
        $fields = [42];
        new Credentials(secretFields: $fields);
    }

    public function rejectsMalformedSecuritySchemeMap(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        new SecurityRequirement(['' => []]);
    }

    public function rejectsCredentialMapsThatAreNotLists(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $headers */
        $headers = ['X-Key' => ['a' => 'value']];
        new Credentials(headers: $headers);
    }

    public function rejectsEmptyCredentialNamesAndSecretLists(): void
    {
        Expect::exception(\InvalidArgumentException::class);
        /** @var array<string, list<string>> $headers */
        $headers = ['' => ['value']];
        new Credentials(headers: $headers);
    }

    public function rejectsMalformedSecretFieldLists(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        /** @var list<string> $fields */
        $fields = [''];
        new Credentials(secretFields: $fields);
    }

    public function keepsTheExistingQueryWhenCredentialQueryIsEmpty(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/pets?existing=1');
        $credentials = new Credentials(query: ['tenant' => []]);

        $request = $credentials->apply($request);

        Assert::same($request->getUri()->getQuery(), 'existing=1');
    }

    public function keepsTheExistingCookiesWhenCredentialCookiesAreEmpty(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/pets')->withHeader('Cookie', 'locale=en');
        $credentials = new Credentials(cookies: ['sid' => []]);

        $request = $credentials->apply($request);

        Assert::same($request->getHeaderLine('Cookie'), 'locale=en');
    }

    public function rejectsASecretFieldMapInsteadOfAList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        /** @var list<string> $fields */
        $fields = ['token' => 'value'];
        new Credentials(secretFields: $fields);
    }

    public function rejectsAScopeMapInsteadOfAList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        /** @var array<string, list<string>> $schemes */
        $schemes = ['oauth' => ['scope' => 'value']];
        new SecurityRequirement($schemes);
    }
}
