<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageIncomplete;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiPropertyTestingException;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every exception the package throws implements the marker, so a caller can
 * catch whatever the package reports without naming each type (#127).
 */
#[Test]
#[Covers(InvalidCase::class)]
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

    public function aRefusalThatAlreadyNamesItsSubjectIsReturnedAsIs(): void
    {
        $refusal = new UnsupportedGeneration('Operation "pets.list" declares no response for status 200');

        Assert::same($refusal->inOperation('pets.list', 'query parameter "limit"'), $refusal);
    }
}
