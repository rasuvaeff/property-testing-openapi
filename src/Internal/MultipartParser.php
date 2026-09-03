<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * Bounded reader of a `multipart/form-data` payload into its parts, the way
 * the SAPI would hand them to PHP: field name, optional filename and part
 * content type, raw value. Malformed framing ends the read at the last
 * well-formed part.
 *
 * @internal
 */
final readonly class MultipartParser
{
    /**
     * @return list<array{name: string, filename: null|string, contentType: null|string, value: string}>
     */
    public function parse(string $body, string $contentType): array
    {
        if (preg_match('/;\s*boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $match) !== 1) {
            return [];
        }
        $delimiter = '--' . ($match[1] !== '' ? $match[1] : $match[2]);
        $cursor = strpos($body, $delimiter);
        if ($cursor === false) {
            return [];
        }
        $cursor += strlen($delimiter);
        $parts = [];
        while (substr($body, $cursor, 2) === "\r\n") {
            $cursor += 2;
            $next = strpos($body, "\r\n" . $delimiter, $cursor);
            if ($next === false) {
                break;
            }
            $part = $this->part(substr($body, $cursor, $next - $cursor));
            if ($part !== null) {
                $parts[] = $part;
            }
            $cursor = $next + 2 + strlen($delimiter);
        }

        return $parts;
    }

    /** @return null|array{name: string, filename: null|string, contentType: null|string, value: string} */
    private function part(string $segment): ?array
    {
        $split = strpos($segment, "\r\n\r\n");
        if ($split === false) {
            return null;
        }
        $disposition = null;
        $contentType = null;
        foreach (explode("\r\n", substr($segment, 0, $split)) as $line) {
            [$header, $value] = array_pad(explode(':', $line, 2), 2, '');
            $header = strtolower(trim($header));
            if ($header === 'content-disposition') {
                $disposition = trim($value);
            } elseif ($header === 'content-type') {
                $contentType = trim($value);
            }
        }
        if ($disposition === null) {
            return null;
        }
        $name = $this->parameter($disposition, 'name');
        if ($name === null) {
            return null;
        }

        return [
            'name' => $name,
            'filename' => $this->parameter($disposition, 'filename'),
            'contentType' => $contentType,
            'value' => substr($segment, $split + 4),
        ];
    }

    private function parameter(string $disposition, string $parameter): ?string
    {
        if (preg_match('/(?:^|;)\s*' . $parameter . '=(?:"((?:[^"\\\\]|\\\\.)*)"|([^;\s]*))/i', $disposition, $match) !== 1) {
            return null;
        }

        return isset($match[2]) && $match[1] === '' ? $match[2] : stripslashes($match[1]);
    }
}
