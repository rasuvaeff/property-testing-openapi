<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * The media type a request or a response does not declare, for the media
 * type mismatch on either side: `application/x-openapi-misuse`, lengthened
 * past any declared key it collides with. A declared wildcard would match it,
 * so a `content` carrying one is refused — the mismatch cannot be promised.
 *
 * @internal
 */
final readonly class UndeclaredMediaType
{
    /**
     * @param array<array-key, mixed> $content
     * @return non-empty-string
     */
    public static function beside(array $content, string $operationKey): string
    {
        foreach (array_keys($content) as $declared) {
            if (is_string($declared) && str_contains($declared, '*')) {
                throw new UnsupportedGeneration(sprintf('Operation "%s" declares wildcard media type "%s"; an undeclared media type cannot be promised', $operationKey, $declared));
            }
        }
        $invalid = 'application/x-openapi-misuse';
        while (array_key_exists($invalid, $content)) {
            $invalid .= '-x';
        }

        return $invalid;
    }
}
