<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Bounded search for a wire value that provably fails a schema `pattern`.
 *
 * The pattern itself is the executable oracle: a candidate is a witness when
 * `preg_match()` returns 0 against the exact regex the validation backend
 * compiles (opis/json-schema wraps the pattern in "\x07...\x07uD"). Candidates
 * are bounded alphabet samples and mutations of a deterministically drawn
 * accepted value; when the candidate, time, or PCRE budget expires the search
 * gives up instead of guessing.
 *
 * @internal
 */
final readonly class PatternWitness
{
    private const int MAX_CANDIDATES = 256;

    private const int TIME_BUDGET_NS = 100_000_000;

    private const string BACKTRACK_LIMIT = '100000';

    private const string RECURSION_LIMIT = '100000';

    private const int ACCEPTED_DRAWS = 3;

    private const int ACCEPTED_SEED = 20_260_901;

    private const array ALPHABET = ['a', 'z', 'A', 'Z', '0', '9', '_', '-', '.', '~', '!'];

    /**
     * @param non-empty-string $pattern
     * @param int<0, max> $minLength
     * @param int<0, max> $maxLength
     */
    public function search(string $pattern, int $minLength, int $maxLength): ?string
    {
        if ($maxLength < $minLength || str_contains($pattern, '\Z')) {
            return null;
        }
        $regex = "\x07{$pattern}\x07uD";
        if (@preg_match($regex, '') === false) {
            return null;
        }
        $backtrack = ini_set('pcre.backtrack_limit', self::BACKTRACK_LIMIT);
        $recursion = ini_set('pcre.recursion_limit', self::RECURSION_LIMIT);

        try {
            return $this->firstWitness($regex, $pattern, $minLength, $maxLength);
        } finally {
            if (is_string($backtrack)) {
                ini_set('pcre.backtrack_limit', $backtrack);
            }
            if (is_string($recursion)) {
                ini_set('pcre.recursion_limit', $recursion);
            }
        }
    }

    /**
     * The `minLength`/`maxLength` window is enforced here, in one place, so a
     * returned witness can never trip a length assertion instead of the
     * pattern.
     *
     * @param non-empty-string $regex
     * @param int<0, max> $minLength
     * @param int<0, max> $maxLength
     */
    private function firstWitness(string $regex, string $pattern, int $minLength, int $maxLength): ?string
    {
        $deadline = hrtime(as_number: true) + self::TIME_BUDGET_NS;
        /** @var array<string, true> $examined */
        $examined = [];
        foreach ($this->candidates($pattern, $minLength) as $candidate) {
            $length = mb_strlen($candidate);
            if ($length < $minLength || $length > $maxLength || isset($examined[$candidate])) {
                continue;
            }
            $examined[$candidate] = true;
            if (count($examined) > self::MAX_CANDIDATES || hrtime(as_number: true) >= $deadline) {
                return null;
            }
            $matched = @preg_match($regex, $candidate);
            if ($matched === false) {
                return null;
            }
            if ($matched === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Three candidate moves, each with a distinct win: alphabet repeats at the
     * window's minimal length probe short strings from every character class,
     * the mixed sample defeats single-class alternations, and appending one
     * character to an accepted value overshoots a pattern that accepts every
     * short string. Dropped moves (character deletion, case swap, reversal,
     * position substitution) cannot defeat a pattern these cannot: the
     * base-length repeats already probe each alphabet class at the shortest
     * admissible length.
     *
     * @param int<0, max> $minLength
     * @return \Generator<int, string>
     */
    private function candidates(string $pattern, int $minLength): \Generator
    {
        yield '';
        $length = max($minLength, 1);
        foreach (self::ALPHABET as $char) {
            yield str_repeat($char, $length);
        }

        yield substr(str_repeat('a0A_', intdiv($length, 4) + 1), 0, $length);
        foreach ($this->acceptedValues($pattern) as $accepted) {
            foreach (self::ALPHABET as $char) {
                yield $accepted . $char;
            }
        }
    }

    /**
     * Accepted values are drawn from the same bounded regex subset the valid
     * generator uses, under a fixed seed so the found witness is stable; a
     * pattern outside the subset simply contributes no mutation candidates.
     *
     * @return list<string>
     */
    private function acceptedValues(string $pattern): array
    {
        try {
            $arbitrary = Gen::stringMatching($pattern);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $random = new Random(self::ACCEPTED_SEED);
        $values = [];
        for ($draw = 0; $draw < self::ACCEPTED_DRAWS; ++$draw) {
            $value = $arbitrary->generate($random)->value;
            if (preg_match('//u', $value) === 1) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
