<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

/**
 * A subschema that has no value at this depth: a reference back to the
 * recursive def whose leaf is being compiled. Not a refusal — the container
 * around it decides whether it can do without the member (an optional
 * property is left out, an array that may be empty is, a nullable value is
 * `null`, a branch of `anyOf`/`oneOf` is skipped) or has to give it up in
 * turn. Only where the def's leaf itself has no value does it become an
 * {@see \Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration}: a
 * recursive schema with no finite instance.
 *
 * @internal
 */
final class Unproducible extends \RuntimeException {}
