<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\ValidationResult;

/**
 * A built-in suite check observed a contract failure for one exchange.
 *
 * @api
 */
final class CheckFailed extends \RuntimeException
{
    public static function invalidGeneratedRequest(string $operationKey, ValidationResult $result): self
    {
        return new self(sprintf('Generated request for operation "%s" is invalid before transport%s', $operationKey, self::summary($result)));
    }

    public static function unexpectedlyValidRequest(string $operationKey): self
    {
        return new self(sprintf('Negative request case for operation "%s" is unexpectedly valid before transport', $operationKey));
    }

    public static function serverError(string $operationKey, int $status): self
    {
        return new self(sprintf('Operation "%s" responded with server error status %d', $operationKey, $status));
    }

    public static function notRejected(string $operationKey, int $status): self
    {
        return new self(sprintf('Operation "%s" answered an invalid request with status %d outside the rejection policy', $operationKey, $status));
    }

    public static function exchangeViolations(string $operationKey, ValidationResult $result): self
    {
        return new self(sprintf('Exchange for operation "%s" violates the contract%s', $operationKey, self::summary($result)));
    }

    private static function summary(ValidationResult $result): string
    {
        $first = $result->violations[0] ?? null;
        if ($first === null) {
            return '';
        }

        return sprintf(': %d violation(s), first [%s] %s', count($result->violations), $first->code, $first->message);
    }
}
