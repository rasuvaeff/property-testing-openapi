<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * The `$defs` of the Schema Object being compiled and the bindings a
 * recursive def is compiled under.
 *
 * The contract compiles a schema that refers to itself — a tree whose
 * `children` are trees — as `$defs` plus a local `{$ref: '#/$defs/<name>'}`
 * for every reference back into the cycle. Generation unfolds that a
 * bounded number of levels: a def is compiled once with its own name bound
 * to the previous level's arbitrary, and once with the name bound to
 * nothing — the leaf, where every reference back to it is unproducible and
 * the containers around it leave it out. This is the mutable state that
 * threads through the compiler's recursion; the compiler itself stays
 * immutable.
 *
 * @internal
 */
final class Definitions
{
    /** @var array<string, array<string, mixed>> */
    private array $defs = [];

    /**
     * A def name bound to an arbitrary while its body is compiled, or to
     * `null` while its leaf is: the level below the last one.
     *
     * @var array<string, ArbitraryInterface|null>
     */
    private array $bindings = [];

    /** @var array<string, true> defs whose body is being merged into an `allOf` */
    private array $merging = [];

    /**
     * Runs $compile with the `$defs` of a Schema Object root in scope. Roots
     * do not nest — a `$defs` map sits on the root the contract emits it on,
     * and every reference into it is resolved against that one map.
     *
     * @template T
     *
     * @param array<array-key, mixed> $defs
     * @param callable(): T $compile
     *
     * @return T
     */
    public function within(array $defs, callable $compile): mixed
    {
        $outer = $this->defs;
        /** @var mixed $body */
        foreach ($defs as $name => $body) {
            if (!is_array($body) || ($body !== [] && array_is_list($body))) {
                throw UnsupportedGeneration::forSchema(sprintf('$defs member "%s" must be a schema object', (string) $name));
            }
            /** @var array<string, mixed> $body */
            $this->defs[(string) $name] = $body;
        }

        try {
            return $compile();
        } finally {
            $this->defs = $outer;
        }
    }

    /**
     * The def a local reference names, by the pointer the contract wrote —
     * `#/$defs/components.schemas.Node`, the name JSON-Pointer-escaped.
     *
     * @return array{string, array<string, mixed>} the name and its body
     */
    public function target(mixed $reference): array
    {
        if (!is_string($reference) || !str_starts_with($reference, '#/$defs/')) {
            throw UnsupportedGeneration::forSchema(sprintf(
                '$ref "%s" is not a local reference into the schema\'s $defs',
                is_string($reference) ? $reference : get_debug_type($reference),
            ));
        }
        /** @var non-empty-string $reference */
        $name = str_replace(['~1', '~0'], ['/', '~'], substr($reference, strlen('#/$defs/')));
        if (!array_key_exists($name, $this->defs)) {
            throw UnsupportedGeneration::forSchema(sprintf('$ref "%s" names no $defs member', $reference));
        }

        return [$name, $this->defs[$name]];
    }

    public function isBound(string $name): bool
    {
        return array_key_exists($name, $this->bindings);
    }

    /**
     * What a reference to a bound def compiles to: the previous level, or
     * nothing at the leaf.
     */
    public function bound(string $name): ?ArbitraryInterface
    {
        return $this->bindings[$name] ?? null;
    }

    /**
     * Runs $compile with $name bound — to the level below, or to nothing.
     *
     * @template T
     *
     * @param callable(): T $compile
     *
     * @return T
     */
    public function bind(string $name, ?ArbitraryInterface $level, callable $compile): mixed
    {
        $this->bindings[$name] = $level;

        try {
            return $compile();
        } finally {
            unset($this->bindings[$name]);
        }
    }

    /**
     * Runs $compile with $names marked as inlined into an `allOf`, so a body
     * that puts itself into its own conjunction is refused rather than
     * inlined without end.
     *
     * @template T
     *
     * @param list<string> $names
     * @param callable(): T $compile
     *
     * @return T
     */
    public function inlining(array $names, callable $compile): mixed
    {
        foreach ($names as $name) {
            if (isset($this->merging[$name])) {
                throw UnsupportedGeneration::forSchema(sprintf('$defs member "%s" is an allOf member of itself', $name));
            }
            $this->merging[$name] = true;
        }

        try {
            return $compile();
        } finally {
            foreach ($names as $name) {
                unset($this->merging[$name]);
            }
        }
    }
}
