<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Implemented by every exception this package throws, so a caller can catch
 * whatever it reports without naming each type:
 *
 * ```php
 * try {
 *     OperationProperty::check($suite, 'pets.get');
 * } catch (OpenApiPropertyTestingException $failure) {
 *     // UnsupportedGeneration, InvalidCase, SuiteConfigurationError,
 *     // CheckFailed, OperationPropertyFailed, CredentialsUnavailable,
 *     // CoverageIncomplete
 * }
 * ```
 *
 * The marker carries nothing itself; each exception keeps its SPL parent,
 * so `catch (\InvalidArgumentException)` and the like keep working.
 *
 * @api
 */
interface OpenApiPropertyTestingException extends \Throwable {}
