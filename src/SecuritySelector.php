<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;

/**
 * Selects the first fully satisfiable OpenAPI security alternative.
 *
 * @api
 */
final readonly class SecuritySelector
{
    /**
     * @return null|array{requirement: SecurityRequirement, credentials: Credentials}
     */
    public function select(Operation $operation, CredentialsProviderInterface $provider): ?array
    {
        if ($operation->security === []) {
            return null;
        }
        foreach ($operation->security as $rawRequirement) {
            $requirement = new SecurityRequirement($rawRequirement);

            try {
                $credentials = $provider->provide($requirement);
            } catch (CredentialsUnavailable) {
                continue;
            }

            return ['requirement' => $requirement, 'credentials' => $credentials];
        }

        throw new CredentialsUnavailable(sprintf('No credentials satisfy operation "%s"', $operation->key));
    }
}
