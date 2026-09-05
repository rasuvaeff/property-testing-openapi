<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Differential;

use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Violation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\DifferentialContracts;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * One generated exchange, two independent readers.
 *
 * `openapi-contract` has carried a differential against
 * `league/openapi-psr7-validator` from the start, and it found real bugs — but
 * it is fed by a hand-written corpus, so it can only disagree about requests
 * someone thought to write down. This package produces requests nobody thought
 * of. Pointing it at the other reader is the half of "the two packages are each
 * other's oracle" that scales: every schema feature added to the fixture
 * multiplies the traffic both implementations have to agree about, at no
 * further authoring cost.
 *
 * The generated *valid* case is the sharp direction. Both readers are given the
 * same document and the same bytes; if one accepts and the other rejects, one
 * of them is wrong about the document, and no amount of internal consistency in
 * either package can settle it. The invalid direction is blunter but still
 * worth asserting: a request built to violate exactly one thing must not slip
 * past either reader. Which violation each one names is its own business — the
 * two report different codes and this does not pretend otherwise.
 *
 * The document is deliberately narrower than the zoo, and
 * {@see DifferentialContracts} records what is left out and why. A differential
 * is informative only on the intersection of what both implementations claim to
 * support; outside it, the test degenerates into a list of the other library's
 * limitations, which is the hand-written corpus this was meant to escape.
 */
#[Test]
#[CoversNothing]
final class LeagueGeneratedDifferentialTest
{
    /**
     * Which operation carries the shape each misuse needs. Spelled out rather
     * than discovered by catching `UnsupportedGeneration`, so a fixture edit
     * that removes the last enum or the last `pattern` fails loudly instead of
     * quietly generating nothing.
     *
     * @var array<string, string>
     */
    private const array MISUSE_SOURCES = [
        'typeMismatch' => 'items.get',
        'enumMismatch' => 'items.get',
        'boundaryMismatch' => 'items.get',
        'lengthMismatch' => 'items.get',
        'patternMismatch' => 'items.get',
        'additionalProperty' => 'items.create',
        'mediaTypeMismatch' => 'items.create',
        'malformedJson' => 'items.create',
    ];

    private ServerRequestValidator $league;

    private RequestMaterializer $materializer;

    #[BeforeTest]
    public function bootReaders(): void
    {
        // Parsing the document is the expensive half of either reader, and a
        // property asks for a verdict several hundred times.
        $this->league = (new ValidatorBuilder())
            ->fromJson(json_encode(DifferentialContracts::document(), JSON_THROW_ON_ERROR))
            ->getServerRequestValidator();
        $factory = new Psr17Factory();
        $this->materializer = new RequestMaterializer($factory, $factory);
    }

    /**
     * @param array{key: string, case: array<string, mixed>} $tagged
     */
    #[Property(runs: 240, generators: [self::class, 'validCase'])]
    public function bothReadersAcceptAGeneratedValidRequest(array $tagged): void
    {
        Classify::when(condition: true, label: $tagged['key']);
        $request = $this->serverRequest($tagged['key'], $tagged['case']);

        Assert::same($this->ourVerdict($request), null);
        Assert::same($this->leagueVerdict($request), null);
    }

    /**
     * One fixed case per operation, before the random phase.
     *
     * @return iterable<string, array{array{key: string, case: array<string, mixed>}}>
     */
    public static function bothReadersAcceptAGeneratedValidRequestExamples(): iterable
    {
        $arbitrary = new RequestCaseArbitrary();
        foreach (DifferentialContracts::OPERATIONS as $key) {
            $case = $arbitrary->forOperation(DifferentialContracts::contract()->operation($key))
                ->generate(new Random(7))
                ->value;
            \assert(is_array($case));

            yield $key => [['key' => $key, 'case' => $case]];
        }
    }

    /**
     * @param array{key: string, kind: string, case: array<string, mixed>} $tagged
     */
    #[Property(runs: 240, generators: [self::class, 'invalidCase'])]
    public function bothReadersRejectAGeneratedInvalidRequest(array $tagged): void
    {
        Classify::when(condition: true, label: $tagged['kind']);
        $request = $this->serverRequest($tagged['key'], $tagged['case']);

        Assert::notSame($this->ourVerdict($request), null);
        Assert::notSame($this->leagueVerdict($request), null);
    }

    /**
     * One fixed case per misuse kind, so every kind is exercised under any
     * seed rather than by a coverage gate on a mixed population.
     *
     * @return iterable<string, array{array{key: string, kind: string, case: array<string, mixed>}}>
     */
    public static function bothReadersRejectAGeneratedInvalidRequestExamples(): iterable
    {
        foreach (self::MISUSE_SOURCES as $kind => $key) {
            $case = self::misuseArbitrary($kind, $key)->generate(new Random(7))->value;
            \assert(is_array($case));

            yield $kind => [['key' => $key, 'kind' => $kind, 'case' => $case]];
        }
    }

    /** @return array<string, ArbitraryInterface> */
    public static function validCase(): array
    {
        $arbitrary = new RequestCaseArbitrary();
        $contract = DifferentialContracts::contract();
        $byKey = [];
        foreach (DifferentialContracts::OPERATIONS as $key) {
            $byKey[$key] = $arbitrary->forOperation($contract->operation($key));
        }

        return ['tagged' => Gen::flatMap(
            Gen::elements(DifferentialContracts::OPERATIONS),
            static function (mixed $key) use ($byKey): ArbitraryInterface {
                \assert(is_string($key));

                return Gen::map($byKey[$key], static fn(array $case): array => ['key' => $key, 'case' => $case]);
            },
        )];
    }

    /** @return array<string, ArbitraryInterface> */
    public static function invalidCase(): array
    {
        $byKind = [];
        foreach (self::MISUSE_SOURCES as $kind => $key) {
            $byKind[$kind] = self::misuseArbitrary($kind, $key);
        }

        return ['tagged' => Gen::flatMap(
            Gen::elements(array_keys(self::MISUSE_SOURCES)),
            static function (mixed $kind) use ($byKind): ArbitraryInterface {
                \assert(is_string($kind));
                $key = self::MISUSE_SOURCES[$kind];

                return Gen::map(
                    $byKind[$kind],
                    static fn(array $case): array => ['key' => $key, 'kind' => $kind, 'case' => $case],
                );
            },
        )];
    }

    private static function misuseArbitrary(string $kind, string $key): ArbitraryInterface
    {
        $operation = DifferentialContracts::contract()->operation($key);
        $arbitrary = new NegativeRequestCaseArbitrary();

        return match ($kind) {
            'typeMismatch' => $arbitrary->typeMismatchForOperation($operation),
            'enumMismatch' => $arbitrary->enumMismatchForOperation($operation),
            'boundaryMismatch' => $arbitrary->boundaryMismatchForOperation($operation),
            'lengthMismatch' => $arbitrary->lengthMismatchForOperation($operation),
            'patternMismatch' => $arbitrary->patternMismatchForOperation($operation),
            'additionalProperty' => $arbitrary->additionalPropertyForOperation($operation),
            'mediaTypeMismatch' => $arbitrary->mediaTypeMismatchForOperation($operation),
            'malformedJson' => $arbitrary->malformedJsonForOperation($operation),
            default => throw new \LogicException('Unknown misuse kind "' . $kind . '"'),
        };
    }

    /** @param array<string, mixed> $case */
    private function serverRequest(string $key, array $case): ServerRequestInterface
    {
        /** @var array{operationKey: string, path: array<string, string|list<string>|array<string, string>>, query: array<string, string|list<string>|array<string, string>>, headers: array<string, string|list<string>|array<string, string>>, cookies: array<string, string|list<string>|array<string, string>>, body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}, misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string}} $case */
        $request = $this->materializer->materialize(DifferentialContracts::contract()->operation($key), $case);

        return $this->asServerRequest($request);
    }

    /**
     * League reads its query from `getQueryParams()`, so the request is handed
     * over the way a SAPI would populate it. That is also why the fixture stays
     * inside what `parse_str()` can express.
     */
    private function asServerRequest(RequestInterface $request): ServerRequestInterface
    {
        parse_str($request->getUri()->getQuery(), $query);
        $server = new ServerRequest($request->getMethod(), $request->getUri(), body: $request->getBody());
        foreach ($request->getHeaders() as $name => $values) {
            $server = $server->withHeader((string) $name, $values);
        }

        return $server->withQueryParams($query);
    }

    private function ourVerdict(ServerRequestInterface $request): ?string
    {
        $result = DifferentialContracts::contract()->validateRequest($request);
        if ($result->isValid()) {
            return null;
        }

        return implode(', ', array_map(
            static fn(Violation $violation): string => $violation->code . ' @ ' . $violation->instancePath,
            $result->violations,
        ));
    }

    private function leagueVerdict(ServerRequestInterface $request): ?string
    {
        try {
            $this->league->validate($request);

            return null;
        } catch (\Throwable $exception) {
            return $exception->getMessage() . ($exception->getPrevious() instanceof \Throwable ? ' <- ' . $exception->getPrevious()->getMessage() : '');
        }
    }
}
