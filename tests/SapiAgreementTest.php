<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\CheckFailed;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageIncomplete;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageReport;
use Rasuvaeff\PropertyTesting\OpenApi\Credentials;
use Rasuvaeff\PropertyTesting\OpenApi\CredentialsUnavailable;
use Rasuvaeff\PropertyTesting\OpenApi\DocumentExamples;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\CaseShape;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Definitions;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\SchemaFacts;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\Unproducible;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ConstructibleCategories;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\DirectionalSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartParser;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\FormatRefusal;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\JsonBodyWitness;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ParameterTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\PatternWitness;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ResponseTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\WitnessCheck;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RedisDsn;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RequestSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ResponseSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\SchemaShape;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiOperations;
use Rasuvaeff\PropertyTesting\OpenApi\OperationCoverage;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;
use Rasuvaeff\PropertyTesting\OpenApi\RedactionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\RequestReproducer;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\SecurityRequirement;
use Rasuvaeff\PropertyTesting\OpenApi\SecuritySelector;
use Rasuvaeff\PropertyTesting\OpenApi\SuiteConfigurationError;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\WireContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * The two packages are each other's oracle, and this is the question neither
 * can answer alone: does the application behind the validator receive the value
 * the generator recorded?
 *
 * A contract validator is only useful if its reading of the wire is the
 * server's reading. When the two differ, the validator's verdict says nothing —
 * it can pass a request the application will mishandle, or fail one the
 * application would have handled correctly. That happened: a `+` in the query
 * was a literal plus to the validator and a space to every SAPI, so the
 * validator reported a violation for the exact value the application receives
 * as correct (openapi-contract#52). Nothing in either package's own suite could
 * see it, because each was self-consistent.
 *
 * The comparison is three-way. `$case` is what the generator wrote down;
 * `Contract::validateRequest()` is asked first, so a disagreement here is never
 * about an invalid request; and `Psr15Transport` hands the case to a handler
 * the way `parse_str()` and the SAPI would. All three have to say the same
 * thing about the same request.
 *
 * Worth being exact about the reach: this covers traffic the generator emits,
 * which is always percent-encoded, so it would *not* by itself have found the
 * `+` bug — that needs a wire form no generator produces. The raw-wire half of
 * the same question is {@see rawWireIsReadTheSameWayByBothReadings()} below,
 * and on the contract's side by its differential against league and cebe.
 * Between them the two halves cover what one alone cannot.
 */
#[Test]
#[Covers(Psr15Transport::class)]
#[Covers(RequestMaterializer::class)]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(BodyTargets::class)]
#[Covers(CallableTransport::class)]
#[Covers(CaseShape::class)]
#[Covers(CheckFailed::class)]
#[Covers(CompositionArbitraries::class)]
#[Covers(ConstructibleCategories::class)]
#[Covers(ContainerArbitraries::class)]
#[Covers(ContractSuite::class)]
#[Covers(CorpusFromEnv::class)]
#[Covers(CoverageIncomplete::class)]
#[Covers(CoverageReport::class)]
#[Covers(Credentials::class)]
#[Covers(CredentialsUnavailable::class)]
#[Covers(Definitions::class)]
#[Covers(DirectionalSchemas::class)]
#[Covers(DocumentExamples::class)]
#[Covers(FormatRefusal::class)]
#[Covers(InvalidCase::class)]
#[Covers(JsonBodyEncoder::class)]
#[Covers(JsonBodyWitness::class)]
#[Covers(LazyPhpRedisCorpusClient::class)]
#[Covers(MediaType::class)]
#[Covers(MultipartParser::class)]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(NegativeResponseCaseArbitrary::class)]
#[Covers(OpenApiOperations::class)]
#[Covers(OperationCoverage::class)]
#[Covers(OperationProperty::class)]
#[Covers(OperationPropertyFailed::class)]
#[Covers(ParameterSchemas::class)]
#[Covers(ParameterSerializer::class)]
#[Covers(ParameterTargets::class)]
#[Covers(PatternWitness::class)]
#[Covers(RedactionPolicy::class)]
#[Covers(RedisDsn::class)]
#[Covers(RejectionPolicy::class)]
#[Covers(RequestReproducer::class)]
#[Covers(RequestSchemas::class)]
#[Covers(ResponseCaseArbitrary::class)]
#[Covers(ResponseMaterializer::class)]
#[Covers(ResponseSchemas::class)]
#[Covers(ResponseTargets::class)]
#[Covers(ScalarArbitraries::class)]
#[Covers(SchemaArbitraryCompiler::class)]
#[Covers(SchemaFacts::class)]
#[Covers(SchemaProbe::class)]
#[Covers(SchemaShape::class)]
#[Covers(SecurityRequirement::class)]
#[Covers(SecuritySelector::class)]
#[Covers(SuiteConfigurationError::class)]
#[Covers(Unproducible::class)]
#[Covers(UnsupportedGeneration::class)]
#[Covers(WireValue::class)]
#[Covers(WitnessCheck::class)]
final class SapiAgreementTest
{
    /**
     * @param array{operationKey: string, path: array<string, string|list<string>|array<string, string>>, query: array<string, string|list<string>|array<string, string>>, headers: array<string, string|list<string>|array<string, string>>, cookies: array<string, string|list<string>|array<string, string>>, body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}, misuse: null} $case
     */
    #[Property(runs: 300, generators: [self::class, 'wireCase'])]
    public function theServerReceivesWhatTheCaseRecorded(array $case): void
    {
        $contract = WireContracts::contract();
        $operation = $contract->operation($case['operationKey']);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        // The validator is one of the three voices, not the judge: a case it
        // rejects would make the rest of the comparison meaningless.
        Assert::true($contract->validateRequest($request)->isValid());

        $handler = $this->recorder();
        (new Psr15Transport($handler, $factory, streams: $factory))->send($request);
        $seen = $handler->request;
        Assert::instanceOf($seen, ServerRequestInterface::class);

        if ($case['operationKey'] === 'wire.get') {
            Classify::cover(condition: true, label: 'query and cookies', minPercent: 20.0);
            $query = $seen->getQueryParams();
            $expected = $case['query'];
            Assert::same($query['q'] ?? null, $expected['q'] ?? null);
            // An exploded object puts each member under its own name, so the
            // SAPI hands them back as top-level pairs.
            foreach (is_array($expected['filter'] ?? null) ? $expected['filter'] : [] as $member => $value) {
                Assert::same($query[$member] ?? null, $value);
            }
            Assert::same($seen->getCookieParams(), $case['cookies']);

            return;
        }

        Classify::cover(condition: true, label: 'form body', minPercent: 20.0);
        Assert::same($seen->getParsedBody(), $case['body']['value'] ?? null);
    }

    /**
     * The other half: wire forms a client sends and a generator never emits.
     * The validator reads the raw query string, the SAPI reads it through
     * `parse_str()`, and the two have to agree — a literal `+`, a valueless
     * key, a percent-encoded space. Each of these was a real divergence
     * (openapi-contract#52, #53); each is now a request both readings answer
     * the same way.
     */
    #[DataProvider('rawWireProvider')]
    public function rawWireIsReadTheSameWayByBothReadings(string $query, string $expected): void
    {
        // The schema admits the expected reading and nothing else, so the
        // validator accepting the request is a statement about what it read —
        // not merely that the value was a string of a permitted length.
        $contract = WireContracts::readingContract($expected);
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/wire?' . $query)
            ->withHeader('Cookie', 'session=s');

        Assert::true($contract->validateRequest($request)->isValid());

        $handler = $this->recorder();
        (new Psr15Transport($handler, $factory, streams: $factory))->send($request);
        $seen = $handler->request;

        Assert::instanceOf($seen, ServerRequestInterface::class);
        Assert::same($seen->getQueryParams()['q'] ?? null, $expected);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rawWireProvider(): iterable
    {
        // The validator is pinned to the same reading by the `q` schema, whose
        // maxLength admits these and whose minLength rejects an empty value.
        yield 'a literal plus is a space' => ['q=a+b&kind=c', 'a b'];
        yield 'a percent-encoded space is a space' => ['q=a%20b&kind=c', 'a b'];
        yield 'a percent-encoded plus is a plus' => ['q=a%2Bb&kind=c', 'a+b'];
        yield 'a valueless foreign key changes nothing' => ['q=ab&kind=c&flag', 'ab'];
    }

    /**
     * The shape a PHP SAPI cannot represent, pinned rather than omitted. An
     * exploded array parameter travels as repeated `name=` pairs — the spelling
     * OpenAPI defines — and `parse_str()` keeps only the last of them, because
     * PHP wants `name[]=`. The contract reads the raw query and recovers the
     * whole list, so the validator and the server genuinely disagree here, and
     * no amount of care in this package can fix it.
     */
    public function anExplodedArrayIsUnreadableByAPhpSapi(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/wire?tags=red&tags=blue');
        $handler = $this->recorder();
        (new Psr15Transport($handler, $factory, streams: $factory))->send($request);
        $seen = $handler->request;

        Assert::instanceOf($seen, ServerRequestInterface::class);
        Assert::same($seen->getQueryParams(), ['tags' => 'blue']);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function wireCase(): array
    {
        $arbitrary = new RequestCaseArbitrary();
        $contract = WireContracts::contract();

        return ['case' => Gen::frequency(array_map(
            static fn(string $key): array => [1, $arbitrary->forOperation($contract->operation($key))],
            WireContracts::OPERATIONS,
        ))];
    }

    private function recorder(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(204);
            }
        };
    }
}
