<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

/**
 * Why no `format` witness could be offered for a schema.
 *
 * The probe used to answer every refusal with a bare `null`, which reads the
 * same whether the schema declares no format at all — nothing to do here —
 * or declares one this package cannot disprove. Those are opposite situations
 * for a document owner: the first is not a gap, and the second is one this
 * package could close. Told apart, a silent absence becomes a reviewable one
 * (#103).
 *
 * `Unsupported` is the case worth surfacing. Someone who writes
 * `format: hostname` and sees the operation's negative phase running has no
 * way to learn that this particular constraint contributes nothing.
 *
 * @internal
 */
enum FormatRefusal
{
    /** The schema does not declare `type: string`, so no string witness applies. */
    case NotAString;

    /** The schema declares no `format`; there is nothing to contradict. */
    case NotDeclared;

    /** The declared `format` is one this package holds no witness for. */
    case Unsupported;
}
