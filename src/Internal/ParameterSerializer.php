<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * @internal
 */
final readonly class ParameterSerializer
{
    /**
     * @param string|list<string>|array<string, string> $value
     */
    public function serialize(string $name, string|array $value, string $style, bool $explode, bool $allowReserved = false): string
    {
        return match ($style) {
            'simple' => $this->simple($value, $explode, ',', $allowReserved),
            'label' => '.' . $this->simple($value, $explode, '.', $allowReserved, encodeDots: true),
            'matrix' => $this->matrix($name, $value, $explode, $allowReserved),
            'form' => $this->form($name, $value, $explode, $allowReserved),
            'spaceDelimited' => $this->delimited($name, $value, ' ', $allowReserved),
            'pipeDelimited' => $this->delimited($name, $value, '|', $allowReserved),
            'deepObject' => $this->deepObject($name, $value, $allowReserved),
            default => throw new UnsupportedGeneration(sprintf('Unsupported parameter style "%s"', $style)),
        };
    }

    /** @param string|list<string>|array<string, string> $value */
    private function simple(string|array $value, bool $explode, string $pairSeparator, bool $allowReserved, bool $encodeDots = false): string
    {
        if (is_string($value)) {
            return $this->encode($value, $allowReserved, $encodeDots);
        }
        if (array_is_list($value)) {
            return implode($pairSeparator, array_map(fn(string $item): string => $this->encode($item, $allowReserved, $encodeDots), $this->list($value)));
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $this->encode($key, $allowReserved, $encodeDots) . ($explode ? '=' : ',') . $this->encode($item, $allowReserved, $encodeDots);
        }

        return implode($explode ? $pairSeparator : ',', $parts);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function matrix(string $name, string|array $value, bool $explode, bool $allowReserved): string
    {
        if (is_string($value)) {
            return ';' . $this->encode($name) . '=' . $this->encode($value, $allowReserved);
        }
        if (array_is_list($value)) {
            $values = $this->list($value);
            if (!$explode) {
                return ';' . $this->encode($name) . '=' . implode(',', array_map(fn(string $item): string => $this->encode($item, $allowReserved), $values));
            }

            return implode('', array_map(fn(string $item): string => ';' . $this->encode($name) . '=' . $this->encode($item, $allowReserved), $values));
        }
        if (!$explode) {
            return ';' . $this->encode($name) . '=' . $this->simple($this->object($value), explode: false, pairSeparator: ',', allowReserved: $allowReserved);
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = ';' . $this->encode($key) . '=' . $this->encode($item, $allowReserved);
        }

        return implode('', $parts);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function form(string $name, string|array $value, bool $explode, bool $allowReserved): string
    {
        if (is_string($value)) {
            return $this->pair($name, $value, $allowReserved);
        }
        if (array_is_list($value)) {
            $values = $this->list($value);
            if (!$explode) {
                return $this->pair($name, implode(',', array_map(fn(string $item): string => $this->encode($item, $allowReserved), $values)), $allowReserved, valueIsEncoded: true);
            }

            return implode('&', array_map(fn(string $item): string => $this->pair($name, $item, $allowReserved), $values));
        }
        if (!$explode) {
            return $this->pair($name, $this->simple($this->object($value), explode: false, pairSeparator: ',', allowReserved: $allowReserved), $allowReserved, valueIsEncoded: true);
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $this->pair($key, $item, $allowReserved);
        }

        return implode('&', $parts);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function delimited(string $name, string|array $value, string $delimiter, bool $allowReserved): string
    {
        if (is_string($value) || !array_is_list($value)) {
            throw new UnsupportedGeneration('Delimited query parameters require a list value');
        }

        return $this->pair($name, implode($delimiter, array_map(fn(string $item): string => $this->encode($item, $allowReserved), $this->list($value))), $allowReserved, valueIsEncoded: true);
    }

    /** @param string|list<string>|array<string, string> $value */
    private function deepObject(string $name, string|array $value, bool $allowReserved): string
    {
        // PHP represents both an empty object and an empty list as `[]`. An
        // empty deepObject has no pairs on the wire, so preserve it as the
        // valid empty object rather than rejecting it as a list.
        if (is_string($value) || ($value !== [] && array_is_list($value))) {
            throw new UnsupportedGeneration('deepObject parameters require an object value');
        }

        /** @var array<array-key, mixed> $value */

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $this->pair($name . '[' . $key . ']', $item, $allowReserved);
        }

        return implode('&', $parts);
    }

    private function pair(string $name, string $value, bool $allowReserved, bool $valueIsEncoded = false): string
    {
        return $this->encode($name) . '=' . ($valueIsEncoded ? $value : $this->encode($value, $allowReserved));
    }

    private function encode(string $value, bool $allowReserved = false, bool $encodeDots = false): string
    {
        $encoded = rawurlencode($value);
        if ($encodeDots) {
            $encoded = str_replace('.', '%2E', $encoded);
        }
        if (!$allowReserved) {
            return $encoded;
        }

        return str_ireplace(
            ['%3A', '%2F', '%3F', '%5B', '%5D', '%40', '%21', '%24', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B'],
            [':', '/', '?', '[', ']', '@', '!', '$', "'", '(', ')', '*', '+', ',', ';'],
            $encoded,
        );
    }

    /** @param array<array-key, mixed> $value
     * @return list<string>
     */
    private function list(array $value): array
    {
        if (!array_is_list($value)) {
            throw new UnsupportedGeneration('Parameter requires a list value');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new UnsupportedGeneration('Parameter list values must be strings');
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /** @param array<array-key, mixed> $value
     * @return array<string, string>
     */
    private function object(array $value): array
    {
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration('Parameter requires an object value');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                throw new UnsupportedGeneration('Parameter object keys and values must be strings');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
