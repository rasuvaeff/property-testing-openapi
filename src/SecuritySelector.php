<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;

/**
 * Selects the first fully satisfiable OpenAPI security alternative.
 *
 * @internal Reach it through {@see ContractSuite::credentials()}.
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

            if ($requirement->schemes === []) {
                return ['requirement' => $requirement, 'credentials' => new Credentials()];
            }

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
