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
}
