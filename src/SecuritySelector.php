<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;

/**
 * Selects the first fully satisfiable OpenAPI security alternative.
 *
 * {@see ContractSuite::credentials()} drives this for a suite; the README
 * documents calling it directly to materialize a single request outside one,
 * which is why it is public. Its whole signature already is: it takes an
 * `Operation` and a {@see CredentialsProviderInterface}, and hands back a
 * {@see SecurityRequirement} with the {@see Credentials} that satisfy it.
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
