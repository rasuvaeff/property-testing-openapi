<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\CaseShape;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

/**
 * A hand-written case that lacks the exported shape is refused by the name
 * of what it lacks at every `@api` entry point, instead of raising a PHP
 * warning and passing (#128).
 */
#[Test]
#[Covers(CaseShape::class)]
final class CaseShapeTest
{
    private const array CASE = ['operationKey' => 'pets.get', 'path' => ['id' => '3'], 'query' => [], 'headers' => [], 'cookies' => [], 'body' => null, 'misuse' => null];

    #[DataProvider('missingKeyProvider')]
    public function aMissingKeyIsNamed(string $key): void
    {
        Expect::exception(InvalidCase::class)->withMessage(sprintf('Case is missing the "%s" key', $key));

        $case = self::CASE;
        unset($case[$key]);
        CaseShape::assert($case);
    }

    public static function missingKeyProvider(): iterable
    {
        foreach (array_keys(self::CASE) as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('wrongTypeProvider')]
    public function aMemberOfTheWrongTypeIsNamed(array $case, string $message): void
    {
        Expect::exception(InvalidCase::class)->withMessage($message);

        CaseShape::assert($case);
    }

    public static function wrongTypeProvider(): iterable
    {
        yield 'operation key' => [array_replace(self::CASE, ['operationKey' => 5]), 'Case "operationKey" must be a string'];
        yield 'query' => [array_replace(self::CASE, ['query' => 'a=b']), 'Case "query" must be a map of parameter values'];
        yield 'body without encoding' => [array_replace(self::CASE, ['body' => ['mediaType' => 'application/json']]), 'Case "body" must be null or carry string "encoding" and "mediaType" members'];
        yield 'body as a string' => [array_replace(self::CASE, ['body' => '{}']), 'Case "body" must be null or carry string "encoding" and "mediaType" members'];
        yield 'misuse without name' => [array_replace(self::CASE, ['misuse' => ['kind' => 'type', 'location' => 'query']]), 'Case "misuse" must be null or carry string "kind", "location" and "name" members'];
    }

    public function aWellFormedCasePasses(): void
    {
        CaseShape::assert(self::CASE);
        CaseShape::assert(array_replace(self::CASE, ['body' => ['encoding' => 'json', 'mediaType' => 'application/json', 'value' => 1], 'misuse' => ['kind' => 'type', 'location' => 'body', 'name' => 'body']]));

        Assert::true(actual: true);
    }

    /**
     * The check stands at every entry point that takes a case, not only at
     * the materializer the suite happens to route through.
     */
    #[DataProvider('entryPointProvider')]
    public function everyEntryPointRefusesACaseWithoutMisuse(\Closure $entry): void
    {
        Expect::exception(InvalidCase::class)->withMessage('Case is missing the "misuse" key');

        $case = self::CASE;
        unset($case['misuse']);
        $factory = new Psr17Factory();
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets/{id}' => ['get' => [
                'operationId' => 'pets.get',
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $suite = ContractSuite::fromContract($contract, $factory, $factory)->operations(['pets.get']);

        $entry($suite, $contract, new RequestMaterializer($factory, $factory), $case);
    }

    public static function entryPointProvider(): iterable
    {
        yield 'checkValid' => [static fn(ContractSuite $suite, Contract $contract, RequestMaterializer $materializer, array $case) => $suite->checkValid('pets.get', $case)];
        yield 'checkNegative' => [static fn(ContractSuite $suite, Contract $contract, RequestMaterializer $materializer, array $case) => $suite->checkNegative('pets.get', $case)];
        yield 'reproduce' => [static fn(ContractSuite $suite, Contract $contract, RequestMaterializer $materializer, array $case) => $suite->reproduce('pets.get', $case)];
        yield 'redact' => [static fn(ContractSuite $suite, Contract $contract, RequestMaterializer $materializer, array $case) => $suite->redact($case)];
        yield 'materialize' => [static fn(ContractSuite $suite, Contract $contract, RequestMaterializer $materializer, array $case) => $materializer->materialize($contract->operation('pets.get'), $case)];
    }
}
