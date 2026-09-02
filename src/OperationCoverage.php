<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Process-local record of the operations a suite exercised and the response
 * statuses it observed, attached with {@see ContractSuite::coverage()}.
 *
 * Hold one instance for the whole run — a static property of the test class
 * is enough — because a suite rebuilt per test case must share it to
 * accumulate. Nothing here is global: a second instance is a second,
 * independent record.
 *
 * @api
 */
final class OperationCoverage
{
    /** @var array<string, array<int, positive-int>> */
    private array $statuses = [];

    public function record(string $operationKey, int $status): void
    {
        $this->statuses[$operationKey][$status] = ($this->statuses[$operationKey][$status] ?? 0) + 1;
    }

    /**
     * The exercised operation keys in first-seen order.
     *
     * @return list<string>
     */
    public function exercised(): array
    {
        return array_keys($this->statuses);
    }

    /**
     * Restricts the record to the given selection: covered and uncovered keys
     * keep the selection order, and statuses are reported only for selected
     * operations, sorted ascending per operation.
     *
     * @param list<string> $selected
     */
    public function report(array $selected): CoverageReport
    {
        $selected = array_values(array_unique($selected));
        $covered = [];
        $uncovered = [];
        $statuses = [];
        foreach ($selected as $key) {
            if (!isset($this->statuses[$key])) {
                $uncovered[] = $key;

                continue;
            }
            $covered[] = $key;
            $observed = $this->statuses[$key];
            ksort($observed);
            $statuses[$key] = $observed;
        }

        return new CoverageReport(selected: $selected, covered: $covered, uncovered: $uncovered, statuses: $statuses);
    }
}
