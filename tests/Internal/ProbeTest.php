<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Internal;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Probe;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Probe::class)]
final class ProbeTest
{
    public function anArbitraryThatDrawsYields(): void
    {
        Assert::true(Probe::yields(Gen::constant(1)));
    }

    public function theImpossibleArbitraryYieldsNothing(): void
    {
        Assert::false(Probe::yields(Probe::impossible()));
    }

    public function aFilterThatPassesOnALaterProbeStillYields(): void
    {
        $calls = 0;
        $arbitrary = Gen::filter(Gen::constant(1), static function () use (&$calls): bool {
            ++$calls;

            // Every retry of the first probe is refused; the second probe passes.
            return $calls > 100;
        });

        Assert::true(Probe::yields($arbitrary));
    }

    public function firstWrapsTheDrawnValueSoNullIsAValue(): void
    {
        Assert::same(Probe::first(Gen::constant(null)), ['value' => null]);
        Assert::null(Probe::first(Probe::impossible()));
    }

    public function firstDrawsFromTheFixedSeed(): void
    {
        $arbitrary = Gen::intBetween(0, 1_000_000);

        Assert::same(Probe::first($arbitrary), ['value' => $arbitrary->generate(new Random(11))->value]);
    }

    public function theImpossibleArbitraryExhausts(): void
    {
        Expect::exception(GenerationExhaustedException::class);

        Probe::impossible()->generate(new Random(1));
    }
}
