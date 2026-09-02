<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Immutable snapshot of one {@see OperationCoverage} record against a suite
 * selection: which selected operations ran at least one trial, which never
 * did, and the response status distribution per exercised operation.
 *
 * The distribution is diagnostic only. A request generator cannot make the
 * server produce every documented status, so statuses are never a gate; the
 * opt-in gate is {@see assertComplete()} over operations.
 *
 * @psalm-type ReportData = array{
 *     selected: list<string>,
 *     covered: list<string>,
 *     uncovered: list<string>,
 *     statuses: array<string, array<int, positive-int>>,
 * }
 *
 * @api
 */
final readonly class CoverageReport
{
    /**
     * @param list<string> $selected
     * @param list<string> $covered
     * @param list<string> $uncovered
     * @param array<string, array<int, positive-int>> $statuses
     */
    public function __construct(
        public array $selected,
        public array $covered,
        public array $uncovered,
        public array $statuses,
    ) {}

    public function isComplete(): bool
    {
        return $this->uncovered === [];
    }

    /**
     * Opt-in gate: fails when any selected operation never ran a trial.
     *
     * @throws CoverageIncomplete
     */
    public function assertComplete(): void
    {
        if (!$this->isComplete()) {
            throw CoverageIncomplete::forReport($this);
        }
    }

    /**
     * JSON-compatible form, stable across runs for the same selection and
     * observations: lists keep the selection order, statuses are sorted.
     *
     * @return ReportData
     */
    public function toArray(): array
    {
        return [
            'selected' => $this->selected,
            'covered' => $this->covered,
            'uncovered' => $this->uncovered,
            'statuses' => $this->statuses,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            ['selected' => $this->selected, 'covered' => $this->covered, 'uncovered' => $this->uncovered, 'statuses' => (object) $this->statuses],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
