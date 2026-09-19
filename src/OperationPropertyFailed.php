<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\CounterExample;

/**
 * One operation property falsified: carries the shrunk minimal case and the
 * redacted curl reproducer alongside the engine's counterexample. A document
 * example that failed is reported under its name, unshrunk, with a
 * counterexample of zero runs.
 *
 * The message prints the case through the suite's redaction policy, the same
 * one the reproducer went through; `$counterExample` keeps the case as
 * generated, for code that needs it rather than a log that must not (#124).
 *
 * @api
 */
final class OperationPropertyFailed extends \RuntimeException implements OpenApiPropertyTestingException
{
    /** @param 'valid'|'negative' $phase */
    private function __construct(
        string $message,
        public readonly string $operationKey,
        public readonly string $phase,
        public readonly CounterExample $counterExample,
        public readonly string $reproducer,
        \Throwable $previous,
        public readonly ?string $example = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param 'valid'|'negative' $phase
     * @param array<string, mixed> $case the case as generated
     * @param array<string, mixed> $redactedCase the case as the message may
     *        print it
     */
    public static function forExample(
        string $operationKey,
        string $phase,
        string $example,
        array $case,
        string $reproducer,
        \Throwable $failure,
        array $redactedCase,
    ): self {
        $cause = $failure->getPrevious() ?? $failure;
        $counterExample = new CounterExample(
            seed: 0,
            runsBeforeFailure: 0,
            originalArguments: ['case' => $case],
            shrunkArguments: ['case' => $case],
            failure: $cause,
        );

        return new self(
            sprintf(
                "Operation \"%s\" failed the %s phase on document example \"%s\": %s\nCase: %s\nReproduce: %s",
                $operationKey,
                $phase,
                $example,
                $cause->getMessage(),
                json_encode($redactedCase, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $reproducer,
            ),
            $operationKey,
            $phase,
            $counterExample,
            $reproducer,
            $failure,
            $example,
        );
    }

    /**
     * @param 'valid'|'negative' $phase
     * @param array<string, mixed> $redactedCase the shrunk case as the
     *        message may print it
     */
    public static function forCounterExample(
        string $operationKey,
        string $phase,
        CounterExample $counterExample,
        string $reproducer,
        \Throwable $failure,
        array $redactedCase,
    ): self {
        $cause = $counterExample->failure ?? $failure;

        return new self(
            sprintf(
                "Operation \"%s\" failed the %s phase after %d run(s) (seed %d): %s\nMinimal case: %s\nReproduce: %s",
                $operationKey,
                $phase,
                $counterExample->runsBeforeFailure,
                $counterExample->seed,
                $cause->getMessage(),
                json_encode($redactedCase, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $reproducer,
            ),
            $operationKey,
            $phase,
            $counterExample,
            $reproducer,
            $failure,
        );
    }
}
