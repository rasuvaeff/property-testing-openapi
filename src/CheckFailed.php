<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\ValidationResult;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;

/**
 * A built-in suite check observed a contract failure for one exchange.
 *
 * When the failure is a validation result, `$result` keeps the structured
 * violations and the message renders every one of them through
 * `ValidationResultFormatter` — operation, code, location, instance path,
 * spec pointer, bounded expected/actual — deterministically and with the
 * formatter's redaction of sensitive actual values.
 *
 * @api
 */
final class CheckFailed extends \RuntimeException
{
    /**
     * The structured validation result behind the message, when the failure
     * is a validation outcome; assigned by the factory, `null` otherwise.
     */
    public ?ValidationResult $result = null;

    public static function invalidGeneratedRequest(string $operationKey, ValidationResult $result): self
    {
        return self::withResult(self::diagnostics(sprintf('Generated request for operation "%s" is invalid before transport', $operationKey), $result), $result);
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
        return self::withResult(self::diagnostics(sprintf('Exchange for operation "%s" violates the contract', $operationKey), $result), $result);
    }

    private static function withResult(string $message, ValidationResult $result): self
    {
        $failure = new self($message);
        $failure->result = $result;

        return $failure;
    }

    private static function diagnostics(string $headline, ValidationResult $result): string
    {
        if ($result->isValid()) {
            return $headline;
        }

        return $headline . "\n" . (new ValidationResultFormatter())->format($result);
    }
}
