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
     * The anonymous alternative — an empty requirement object, which OpenAPI
     * uses to say "authentication is optional" — is kept as a fallback rather
     * than taken as soon as it is seen. Listed first, as it usually is, it
     * short-circuited the search and the suite never exercised the
     * authenticated path of such an operation at all: half its coverage, lost
     * silently. An alternative the provider can satisfy is preferred; the
     * anonymous one answers only when none can.
     *
     * @return null|array{requirement: SecurityRequirement, credentials: Credentials}
     */
    public function select(Operation $operation, CredentialsProviderInterface $provider): ?array
    {
        if ($operation->security === []) {
            return null;
        }
        $anonymous = null;
        foreach ($operation->security as $rawRequirement) {
            $requirement = new SecurityRequirement($rawRequirement);

            if ($requirement->schemes === []) {
                $anonymous ??= ['requirement' => $requirement, 'credentials' => new Credentials()];

                continue;
            }

            try {
                $credentials = $provider->provide($requirement);
            } catch (CredentialsUnavailable) {
                continue;
            }

            return ['requirement' => $requirement, 'credentials' => $credentials];
        }
        if ($anonymous !== null) {
            return $anonymous;
        }

        throw new CredentialsUnavailable(sprintf('No credentials satisfy operation "%s"', $operation->key));
    }
}
