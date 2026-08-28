<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RejectionPolicy::class)]
final class RejectionPolicyTest
{
    #[DataProvider('acceptanceProvider')]
    public function matchesExactCodesAndRanges(RejectionPolicy $policy, string $operationKey, int $status, bool $expected): void
    {
        Assert::same($policy->accepts($operationKey, $status), $expected);
    }

    public static function acceptanceProvider(): iterable
    {
        $policy = RejectionPolicy::rejectWith('4XX');

        yield 'range accepts 400' => [$policy, 'pets.get', 400, true];
        yield 'range accepts 422' => [$policy, 'pets.get', 422, true];
        yield 'range rejects 200' => [$policy, 'pets.get', 200, false];
        yield 'range rejects 500' => [$policy, 'pets.get', 500, false];
        yield 'exact accepts only itself' => [RejectionPolicy::rejectWith(400, 422), 'pets.get', 409, false];
        yield 'exact accepts listed code' => [RejectionPolicy::rejectWith(400, 422), 'pets.get', 422, true];
    }

    public function operationOverrideReplacesTheDefaults(): void
    {
        $policy = RejectionPolicy::rejectWith('4XX')->forOperation('legacy.get', 200);

        Assert::true($policy->accepts('legacy.get', 200));
        Assert::false($policy->accepts('legacy.get', 400));
        Assert::true($policy->accepts('pets.get', 400));
    }

    public function rejectsAnEmptySelectorList(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        RejectionPolicy::rejectWith();
    }

    public function rejectsAStatusOutsideTheValidRange(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        RejectionPolicy::rejectWith(99);
    }

    public function rejectsAMalformedRangeSelector(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        RejectionPolicy::rejectWith('4xx');
    }

    public function rejectsAnEmptyOperationKey(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        RejectionPolicy::rejectWith('4XX')->forOperation('', 400);
    }

    #[Property(runs: 200)]
    public function rangeSelectorAgreesWithIntegerDivision(int $status): void
    {
        $policy = RejectionPolicy::rejectWith('4XX');

        Assert::same($policy->accepts('any.operation', $status), intdiv($status, 100) === 4);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function rangeSelectorAgreesWithIntegerDivisionGenerators(): array
    {
        return ['status' => Gen::intBetween(100, 599)];
    }
}
