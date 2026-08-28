<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Signals that a credentials provider cannot satisfy one alternative.
 *
 * @api
 */
final class CredentialsUnavailable extends \RuntimeException {}
