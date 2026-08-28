<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Opt-in oracle for the negative phase: the statuses an operation is allowed
 * to answer to an invalid request. OpenAPI itself does not promise
 * `invalid -> 4xx`, so without a policy only the no-5xx check applies.
 *
 * @api
 */
final readonly class RejectionPolicy
{
    /**
     * @param list<int|non-empty-string> $defaults
     * @param array<string, list<int|non-empty-string>> $overrides
     */
    private function __construct(
        private array $defaults,
        private array $overrides,
    ) {}

    /**
     * @param int|non-empty-string ...$statuses exact status codes or `NXX` ranges
     */
    public static function rejectWith(int|string ...$statuses): self
    {
        return new self(self::selectors(array_values($statuses)), []);
    }

    /**
     * @param int|non-empty-string ...$statuses exact status codes or `NXX` ranges
     */
    public function forOperation(string $operationKey, int|string ...$statuses): self
    {
        if ($operationKey === '') {
            throw new \InvalidArgumentException('Operation key must be a non-empty string');
        }
        $overrides = $this->overrides;
        $overrides[$operationKey] = self::selectors(array_values($statuses));

        return new self($this->defaults, $overrides);
    }

    public function accepts(string $operationKey, int $status): bool
    {
        foreach ($this->overrides[$operationKey] ?? $this->defaults as $selector) {
            if (is_int($selector) ? $selector === $status : (int) $selector[0] === intdiv($status, 100)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $statuses
     * @return non-empty-list<int|non-empty-string>
     */
    private static function selectors(array $statuses): array
    {
        if ($statuses === []) {
            throw new \InvalidArgumentException('A rejection policy requires at least one accepted status selector');
        }
        foreach ($statuses as $status) {
            if (is_int($status)) {
                if ($status < 100 || $status > 599) {
                    throw new \InvalidArgumentException(sprintf('Status code %d is outside the 100-599 range', $status));
                }
            } elseif (preg_match('/^[1-5]XX\z/', $status) !== 1) {
                throw new \InvalidArgumentException(sprintf('Status selector "%s" must be an NXX range', $status));
            }
        }

        /** @var non-empty-list<int|non-empty-string> $statuses */

        return $statuses;
    }
}
