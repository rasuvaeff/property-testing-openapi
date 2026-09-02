<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Raised by {@see CoverageReport::assertComplete()} when a selected operation
 * never ran a trial; the full report stays available in {@see $report}.
 *
 * @api
 */
final class CoverageIncomplete extends \RuntimeException
{
    private function __construct(
        public readonly CoverageReport $report,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forReport(CoverageReport $report): self
    {
        return new self($report, sprintf(
            'Operation coverage is incomplete: %d of %d selected operation(s) never ran a trial (%s)',
            count($report->uncovered),
            count($report->selected),
            implode(', ', $report->uncovered),
        ));
    }
}
