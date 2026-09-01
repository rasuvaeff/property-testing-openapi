<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\CounterExample;

/**
 * One operation property falsified: carries the shrunk minimal case and the
 * redacted curl reproducer alongside the engine's counterexample rendering.
 * A document example that failed is reported under its name, unshrunk, with
 * a counterexample of zero runs.
 *
 * @api
 */
final class OperationPropertyFailed extends \RuntimeException
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
     * @param array<string, mixed> $case
     */
    public static function forExample(
        string $operationKey,
        string $phase,
        string $example,
        array $case,
        string $reproducer,
        \Throwable $failure,
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
                json_encode($case, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
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

    /** @param 'valid'|'negative' $phase */
    public static function forCounterExample(
        string $operationKey,
        string $phase,
        CounterExample $counterExample,
        string $reproducer,
        \Throwable $failure,
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
                $counterExample->toJson(),
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
