<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * An argument that configures the package is malformed or out of range: a
 * base URI, a rejection or redaction policy, credentials, a security
 * requirement, the run count, or a `PROPERTY_*` value from the environment.
 * A caller error, like {@see InvalidCase}, over configuration rather than
 * over case data.
 *
 * @api
 */
final class InvalidConfiguration extends \InvalidArgumentException implements OpenApiPropertyTestingException {}
