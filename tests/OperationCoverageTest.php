<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageIncomplete;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageReport;
use Rasuvaeff\PropertyTesting\OpenApi\OperationCoverage;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(OperationCoverage::class)]
#[Covers(CoverageReport::class)]
#[Covers(CoverageIncomplete::class)]
final class OperationCoverageTest
{
    private const array KEYS = ['pets.list', 'pets.get', 'pets.create', 'secure.get', 'ping'];

    public function reportKeepsSelectionOrderAndSplitsCoveredFromUncovered(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.get', 204);
        $coverage->record('pets.list', 400);

        $report = $coverage->report(['pets.list', 'secure.get', 'pets.get']);

        Assert::same($report->selected, ['pets.list', 'secure.get', 'pets.get']);
        Assert::same($report->covered, ['pets.list', 'pets.get']);
        Assert::same($report->uncovered, ['secure.get']);
        Assert::false($report->isComplete());
    }

    public function reportCountsStatusesPerOperationSortedAscending(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.get', 400);
        $coverage->record('pets.get', 204);
        $coverage->record('pets.get', 400);
        $coverage->record('pets.get', 500);

        $report = $coverage->report(['pets.get']);

        Assert::same($report->statuses, ['pets.get' => [204 => 1, 400 => 2, 500 => 1]]);
        Assert::same(array_keys($report->statuses['pets.get']), [204, 400, 500]);
    }

    public function reportIgnoresOperationsOutsideTheSelection(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.create', 201);
        $coverage->record('pets.get', 204);

        $report = $coverage->report(['pets.get']);

        Assert::same($report->covered, ['pets.get']);
        Assert::same($report->statuses, ['pets.get' => [204 => 1]]);
        Assert::same($coverage->exercised(), ['pets.create', 'pets.get']);
    }

    public function reportDeduplicatesTheSelection(): void
    {
        $report = (new OperationCoverage())->report(['pets.get', 'pets.get', 'ping']);

        Assert::same($report->selected, ['pets.get', 'ping']);
        Assert::same($report->uncovered, ['pets.get', 'ping']);
    }

    public function emptySelectionIsComplete(): void
    {
        $report = (new OperationCoverage())->report([]);

        Assert::true($report->isComplete());
        Assert::same($report->toJson(), '{"selected":[],"covered":[],"uncovered":[],"statuses":{}}');
        $report->assertComplete();
    }

    public function assertCompleteThrowsWithTheReportAttached(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.get', 204);
        $report = $coverage->report(['pets.get', 'pets.list', 'ping']);

        try {
            $report->assertComplete();
            Assert::true(actual: false, message: 'Expected CoverageIncomplete');
        } catch (CoverageIncomplete $failure) {
            Assert::same($failure->report, $report);
            Assert::same($failure->getMessage(), 'Operation coverage is incomplete: 2 of 3 selected operation(s) never ran a trial (pets.list, ping)');
        }
    }

    public function assertCompletePassesWhenEveryOperationRan(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.get', 204);
        $coverage->record('ping', 500);

        $coverage->report(['pets.get', 'ping'])->assertComplete();

        Assert::true($coverage->report(['pets.get', 'ping'])->isComplete());
    }

    public function toJsonRendersStatusesAsObjects(): void
    {
        $coverage = new OperationCoverage();
        $coverage->record('pets.get', 204);
        $coverage->record('pets.get', 204);
        $coverage->record('pets/ünicode', 404);
        $report = $coverage->report(['pets.get', 'pets/ünicode', 'ping']);

        Assert::same(
            $report->toJson(),
            '{"selected":["pets.get","pets/ünicode","ping"],"covered":["pets.get","pets/ünicode"],"uncovered":["ping"],"statuses":{"pets.get":{"204":2},"pets/ünicode":{"404":1}}}',
        );
        Assert::same(json_decode($report->toJson(), associative: true), $report->toArray());
    }

    /**
     * @param list<string> $selected
     * @param list<array{string, int}> $records
     */
    #[Property(runs: 300)]
    public function coveredAndUncoveredPartitionTheSelection(array $selected, array $records): void
    {
        $coverage = new OperationCoverage();
        foreach ($records as [$key, $status]) {
            $coverage->record($key, $status);
        }
        $report = $coverage->report($selected);

        Classify::cover($report->covered !== [], 'some covered', 20.0);
        Classify::cover($report->uncovered !== [], 'some uncovered', 20.0);

        Assert::same(array_values(array_intersect($report->selected, $report->covered)), $report->covered);
        Assert::same(array_values(array_diff($report->selected, $report->covered)), $report->uncovered);
        Assert::same(array_keys($report->statuses), $report->covered);
        Assert::same($report->isComplete(), $report->uncovered === []);
        foreach ($report->covered as $key) {
            $expected = count(array_filter($records, static fn(array $record): bool => $record[0] === $key));
            Assert::same(array_sum($report->statuses[$key]), $expected);
            $sorted = $report->statuses[$key];
            ksort($sorted);
            Assert::same($report->statuses[$key], $sorted);
        }
        Assert::same(json_decode($report->toJson(), associative: true), $report->toArray());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function coveredAndUncoveredPartitionTheSelectionGenerators(): array
    {
        return [
            'selected' => Gen::uniqueArrayOf(Gen::elements(self::KEYS), 0, 5),
            'records' => Gen::arrayOf(Gen::tuple(Gen::elements(self::KEYS), Gen::intBetween(100, 599)), 0, 12),
        ];
    }

    public static function coveredAndUncoveredPartitionTheSelectionExamples(): iterable
    {
        yield 'nothing selected, nothing recorded' => [[], []];
        yield 'everything selected, one recorded' => [self::KEYS, [['ping', 204]]];
        yield 'recorded outside the selection' => [['pets.get'], [['ping', 204], ['ping', 500]]];
    }
}
