<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageIncomplete;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidConfiguration;
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiPropertyTestingException;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;
use Rasuvaeff\PropertyTesting\OpenApi\RedactionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SecurityRequirement;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ZooContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * Every exception the package throws implements the marker, so a caller can
 * catch whatever the package reports without naming each type (#127).
 */
#[Test]
#[Covers(InvalidCase::class)]
#[Covers(InvalidConfiguration::class)]
#[Covers(UnsupportedGeneration::class)]
final class ExceptionsTest
{
    /** @param class-string<\Throwable> $class */
    #[DataProvider('exceptionClassProvider')]
    public function everyExceptionOfThePackageImplementsTheMarker(string $class): void
    {
        Assert::true(is_subclass_of($class, OpenApiPropertyTestingException::class), $class);
        Assert::true(is_subclass_of($class, \Throwable::class), $class);
    }

    public static function exceptionClassProvider(): iterable
    {
        yield CheckFailed::class => [CheckFailed::class];
        yield CoverageIncomplete::class => [CoverageIncomplete::class];
        yield CredentialsUnavailable::class => [CredentialsUnavailable::class];
        yield InvalidCase::class => [InvalidCase::class];
        yield InvalidConfiguration::class => [InvalidConfiguration::class];
        yield OperationPropertyFailed::class => [OperationPropertyFailed::class];
        yield SuiteConfigurationError::class => [SuiteConfigurationError::class];
        yield UnsupportedGeneration::class => [UnsupportedGeneration::class];
    }

    /**
     * The list above is the whole set: a new exception class in `src/` that
     * forgets the marker is caught here, not by a consumer.
     */
    public function theProviderNamesEveryExceptionClassInSrc(): void
    {
        $found = [];
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            $class = 'Rasuvaeff\\PropertyTesting\\OpenApi\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, \Throwable::class)) {
                $found[] = $class;
            }
        }
        sort($found);

        Assert::same($found, array_keys(iterator_to_array(self::exceptionClassProvider())));
    }

    public function invalidCaseNamesTheMissingKey(): void
    {
        $failure = InvalidCase::missingKey('misuse');

        Assert::same($failure->getMessage(), 'Case is missing the "misuse" key');
        Assert::instanceOf($failure, \InvalidArgumentException::class);
    }

    public function aSchemaRefusalIsPlacedInItsOperation(): void
    {
        $refusal = UnsupportedGeneration::forSchema('minLength exceeds maxLength');
        $placed = $refusal->inOperation('pets.list', 'query parameter "limit"');

        Assert::same($refusal->getMessage(), 'Unsupported OpenAPI schema generation: minLength exceeds maxLength');
        Assert::same($placed->getMessage(), 'Unsupported OpenAPI schema generation for operation "pets.list", query parameter "limit": minLength exceeds maxLength');
        Assert::same($placed->getPrevious(), $refusal);
        Assert::same($placed->getCode(), 0);
        Assert::same($placed->inOperation('other', 'body')->getMessage(), 'Unsupported OpenAPI schema generation for operation "other", body: minLength exceeds maxLength');
    }

    /**
     * An argument the package refuses carries the marker too: these threw a
     * bare `InvalidArgumentException`, which a `catch` on the marker the
     * README promises let through (#162).
     *
     * @param \Closure(): mixed $call
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('refusedArgumentProvider')]
    public function aRefusedArgumentCarriesTheMarker(\Closure $call, string $class): void
    {
        Expect::exception($class);

        $call();
    }

    public static function refusedArgumentProvider(): iterable
    {
        $factory = new Psr17Factory();
        $case = ['operationKey' => 'users.create', 'path' => [], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null];

        yield 'base URI' => [static fn(): mixed => (new RequestMaterializer($factory, $factory))->withBaseUri('//host'), InvalidConfiguration::class];
        yield 'rejection status' => [static fn(): mixed => RejectionPolicy::rejectWith(700), InvalidConfiguration::class];
        yield 'rejection operation' => [static fn(): mixed => RejectionPolicy::rejectWith(400)->forOperation('', 400), InvalidConfiguration::class];
        yield 'redaction names' => [static fn(): mixed => new RedactionPolicy(headers: ['']), InvalidConfiguration::class];
        yield 'credential secret fields' => [static fn(): mixed => new Credentials(secretFields: ['']), InvalidConfiguration::class];
        yield 'security scopes' => [static fn(): mixed => new SecurityRequirement(['oauth' => [1]]), InvalidConfiguration::class];
        yield 'run count' => [static fn(): mixed => OperationProperty::check(ZooContracts::suite(), 'users.create', runs: 0), InvalidConfiguration::class];
        yield 'negative check of a valid case' => [static fn(): mixed => ZooContracts::suite()->checkNegative('users.create', $case), InvalidCase::class];
    }

    public function aRefusalThatAlreadyNamesItsSubjectIsReturnedAsIs(): void
    {
        $refusal = new UnsupportedGeneration('Operation "pets.list" declares no response for status 200');

        Assert::same($refusal->inOperation('pets.list', 'query parameter "limit"'), $refusal);
    }
}
