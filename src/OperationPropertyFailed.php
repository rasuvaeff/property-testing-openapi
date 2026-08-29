<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\CounterExample;

/**
 * One operation property falsified: carries the shrunk minimal case and the
 * redacted curl reproducer alongside the engine's counterexample rendering.
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
    ) {
        parent::__construct($message, 0, $previous);
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
