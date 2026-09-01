<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\PatternWitness;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Direct probes of the pattern witness search: the public arbitrary is
 * fail-closed on an unprovable schema before the search can even run, so the
 * guard and budget behaviour is only observable here.
 */
#[Test]
#[Covers(PatternWitness::class)]
#[Covers(SchemaProbe::class)]
final class PatternWitnessTest
{
    public function findsAWitnessInsideAClosedLengthWindow(): void
    {
        Assert::same((new PatternWitness())->search('^a+$', 2, 2), 'zz');
    }

    public function findsAnAlphabetWitnessAtTheMinimalLength(): void
    {
        Assert::same((new PatternWitness())->search('^a*$', 0, SchemaProbe::MAX_CONSTRUCTED_LENGTH), 'z');
    }

    public function findsAMutationWitnessBeyondTheAlphabetSamples(): void
    {
        Assert::same((new PatternWitness())->search('^.{0,1}$', 0, SchemaProbe::MAX_CONSTRUCTED_LENGTH), 'Qa');
    }

    public function findsTheMixedSampleWitnessAgainstASingleClassAlternation(): void
    {
        Assert::same(
            (new PatternWitness())->search('^(a+|z+|A+|Z+|0+|9+|_+|-+|\.+|~+|!+)$', 5, 5),
            'a0A_a',
        );
    }

    public function refusesWhenTheWindowHidesEveryCounterexample(): void
    {
        Assert::null((new PatternWitness())->search('^.{0,2}$', 0, 2));
        Assert::null((new PatternWitness())->search('^([ab]{4}|.)$', 1, 1));
    }

    public function refusesAPatternTheBackendWouldReject(): void
    {
        Assert::null((new PatternWitness())->search('a\Z', 0, SchemaProbe::MAX_CONSTRUCTED_LENGTH));
    }

    public function restoresPcreLimitsAfterTheSearch(): void
    {
        $backtrack = ini_set('pcre.backtrack_limit', '654321');
        $recursion = ini_set('pcre.recursion_limit', '123456');

        try {
            (new PatternWitness())->search('^a+$', 2, 2);

            Assert::same(ini_get('pcre.backtrack_limit'), '654321');
            Assert::same(ini_get('pcre.recursion_limit'), '123456');
        } finally {
            if (is_string($backtrack)) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }
            if (is_string($recursion)) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }
    }

    public function patternConstraintsReturnTheClampedWindow(): void
    {
        $probe = new SchemaProbe();
        $max = SchemaProbe::MAX_CONSTRUCTED_LENGTH;

        Assert::same(
            $probe->patternConstraints(['type' => 'string', 'pattern' => '^a+$']),
            ['pattern' => '^a+$', 'minLength' => 0, 'maxLength' => $max],
        );
        Assert::same(
            $probe->patternConstraints(['type' => 'string', 'pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 3]),
            ['pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 3],
        );
        Assert::same(
            $probe->patternConstraints(['type' => 'string', 'pattern' => '^a+$', 'maxLength' => $max + 1]),
            ['pattern' => '^a+$', 'minLength' => 0, 'maxLength' => $max],
        );
        Assert::same(
            $probe->patternConstraints(['type' => 'string', 'pattern' => '^a+$', 'minLength' => $max]),
            ['pattern' => '^a+$', 'minLength' => $max, 'maxLength' => $max],
        );
        Assert::same(
            $probe->patternConstraints(['type' => 'string', 'pattern' => '^a+$', 'maxLength' => 0]),
            ['pattern' => '^a+$', 'minLength' => 0, 'maxLength' => 0],
        );
    }

    public function patternConstraintsRejectEveryUnprovableSchema(): void
    {
        $probe = new SchemaProbe();
        foreach ([
            'non-string type' => ['type' => 'integer', 'pattern' => '^a+$'],
            'missing pattern' => ['type' => 'string'],
            'empty pattern' => ['type' => 'string', 'pattern' => ''],
            'non-string pattern' => ['type' => 'string', 'pattern' => ['^a+$']],
            'enum conflict' => ['type' => 'string', 'pattern' => '^a+$', 'enum' => ['a']],
            'const conflict' => ['type' => 'string', 'pattern' => '^a+$', 'const' => 'a'],
            'format conflict' => ['type' => 'string', 'pattern' => '^a+$', 'format' => 'uuid'],
            'negative minLength' => ['type' => 'string', 'pattern' => '^a+$', 'minLength' => -1],
            'negative maxLength' => ['type' => 'string', 'pattern' => '^a+$', 'maxLength' => -1],
            'inverted window' => ['type' => 'string', 'pattern' => '^a+$', 'minLength' => 3, 'maxLength' => 2],
            'minLength beyond budget' => ['type' => 'string', 'pattern' => '^a+$', 'minLength' => SchemaProbe::MAX_CONSTRUCTED_LENGTH + 1],
        ] as $name => $schema) {
            Assert::null($probe->patternConstraints($schema), message: sprintf('Expected null constraints for %s', $name));
        }
    }
}
