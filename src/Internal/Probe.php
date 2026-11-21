<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\Random;

/**
 * The compile-time question every filtered arbitrary is asked before it is
 * offered: does it produce anything at all? Deterministic draws, each with the
 * filter's own retry budget, so an arbitrary that fails here is one that would
 * have exhausted mid-run (#163). The seed and the budget are part of the
 * package's reproducibility: a probe draws from its own `Random`, never from
 * the run's, and the answer must not move between releases.
 *
 * @internal
 */
final readonly class Probe
{
    private const int PROBES = 8;

    private const int SEED = 11;

    public static function yields(ArbitraryInterface $arbitrary): bool
    {
        $random = new Random(self::SEED);
        for ($probe = 0; $probe < self::PROBES; ++$probe) {
            try {
                $arbitrary->generate($random);

                return true;
            } catch (GenerationExhaustedException) {
                continue;
            }
        }

        return false;
    }

    /**
     * The arbitrary that admits nothing: the `false` schema, or a branch whose
     * conjunction is known to be empty.
     */
    public static function impossible(): ArbitraryInterface
    {
        return Gen::filter(Gen::constant(null), static fn(): bool => false);
    }
}
