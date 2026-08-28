<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

/**
 * Supplies credentials for one complete OpenAPI security alternative.
 *
 * @api
 */
interface CredentialsProviderInterface
{
    public function provide(SecurityRequirement $requirement): Credentials;
}
