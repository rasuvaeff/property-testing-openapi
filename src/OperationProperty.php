<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\ExampleFailed;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

/**
 * Framework-neutral operation property: runs the suite's built-in checks for
 * one selected operation through the property engine — the valid phase always,
 * the negative phase when the operation supports a constructible misuse
 * category. Call it from a plain test method under any runner; a falsified
 * phase surfaces as {@see OperationPropertyFailed} with the shrunk minimal
 * case and a redacted curl reproducer.
 *
 * The valid phase starts with the document's own examples
 * ({@see ContractSuite::exampleCases()}): they run before corpus replay and
 * the random phase under every seed, so a point fault the document itself
 * describes is found on the first run instead of by chance. A failing example
 * is reported by name, unshrunk.
 *
 * Environment parity with the property-testing adapters: `PROPERTY_RUNS`
 * overrides the run count, `PROPERTY_SEED` fixes the seed unless an explicit
 * seed is given, and `PROPERTY_DB` names a directory-backed or Redis regression
 * corpus.
 *
 * @api
 *
 * @psalm-import-type CaseData from ContractSuite
 */
final readonly class OperationProperty
{
    public static function check(ContractSuite $suite, string $operationKey, int $runs = 100, ?int $seed = null): void
    {
        $runs = self::resolveRuns($runs);
        $seed ??= self::resolveSeed();
        $corpus = self::resolveCorpus();

        self::run($suite, $operationKey, phase: 'valid', cases: $suite->validCases($operationKey), runs: $runs, seed: $seed, corpus: $corpus, examples: $suite->exampleCases($operationKey));

        try {
            $negative = $suite->negativeCases($operationKey);
        } catch (UnsupportedGeneration) {
            return;
        }

        self::run($suite, $operationKey, phase: 'negative', cases: $negative, runs: $runs, seed: $seed, corpus: $corpus);
    }

    /**
     * @param 'valid'|'negative' $phase
     * @param array<string, CaseData> $examples
     */
    private static function run(
        ContractSuite $suite,
        string $operationKey,
        string $phase,
        \Rasuvaeff\PropertyTesting\ArbitraryInterface $cases,
        int $runs,
        ?int $seed,
        ?Corpus $corpus,
        array $examples = [],
    ): void {
        $exampleNames = array_map(strval(...), array_keys($examples));
        $exampleCases = array_values($examples);
        $definition = new PropertyDefinition(
            id: sprintf('openapi::%s::%s', $operationKey, $phase),
            name: sprintf('%s %s', $operationKey, $phase),
            generators: ['case' => $cases],
            parameterNames: ['case'],
            config: new PropertyConfig(runs: $runs, seed: $seed),
            examples: array_map(static fn(array $case): array => [$case], $exampleCases),
            replayRegressions: $seed === null,
        );
        $executor = new CallableTrialExecutor(static function (mixed $case) use ($suite, $operationKey, $phase): void {
            if (!is_array($case)) {
                throw new \LogicException('Generated request case has an invalid shape');
            }
            /** @var CaseData $case */
            $phase === 'valid' ? $suite->checkValid($operationKey, $case) : $suite->checkNegative($operationKey, $case);
        });

        $result = (new PropertyRunner())->run($definition, $executor, corpus: $corpus);
        $failure = $result->failure();
        if (!$failure instanceof \Throwable) {
            return;
        }
        if ($result instanceof Falsified) {
            throw OperationPropertyFailed::forCounterExample(
                $operationKey,
                $phase,
                $result->counterExample(),
                self::reproducer($suite, $operationKey, $result->counterExample()->shrunkArguments),
                $failure,
            );
        }
        if ($result instanceof ExampleFailed) {
            $index = $result->exception->getIndex();
            $case = $exampleCases[$index] ?? null;
            $name = $exampleNames[$index] ?? null;
            if ($case === null || $name === null) {
                throw new \LogicException('Failed example index is outside the example set');
            }

            throw OperationPropertyFailed::forExample(
                $operationKey,
                $phase,
                $name,
                $case,
                self::reproducer($suite, $operationKey, ['case' => $case]),
                $result->exception,
            );
        }

        throw $failure;
    }

    /** @param array<array-key, mixed> $arguments */
    private static function reproducer(ContractSuite $suite, string $operationKey, array $arguments): string
    {
        $case = $arguments['case'] ?? null;
        if (!is_array($case)) {
            return '(no reproducer: counterexample case is unavailable)';
        }

        try {
            /** @var CaseData $case */
            return $suite->reproduce($operationKey, $case);
        } catch (\Throwable $failure) {
            return sprintf('(no reproducer: %s)', $failure->getMessage());
        }
    }

    private static function resolveRuns(int $runs): int
    {
        if ($runs < 1) {
            throw new \InvalidArgumentException('Runs must be greater than or equal to 1');
        }
        $env = getenv('PROPERTY_RUNS');
        if ($env === false || $env === '') {
            return $runs;
        }
        if (preg_match('/^[1-9][0-9]*\z/', $env) !== 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_RUNS must be a positive integer, got "%s"', $env));
        }

        return (int) $env;
    }

    private static function resolveSeed(): ?int
    {
        $env = getenv('PROPERTY_SEED');
        if ($env === false || $env === '') {
            return null;
        }
        if (preg_match('/^-?[0-9]+\z/', $env) !== 1) {
            throw new \InvalidArgumentException(sprintf('PROPERTY_SEED must be an integer, got "%s"', $env));
        }

        return (int) $env;
    }

    private static function resolveCorpus(): ?Corpus
    {
        return CorpusFromEnv::resolve();
    }
}
