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
     * @param bool $percentEncoded whether this wire escapes a value at all. A
     *        path and a query do; a header does not — HTTP reads a field value
     *        as opaque octets, and the validator reads it as sent
     *        (openapi-contract#66), so encoding one here would put a string on
     *        the wire that no client sends. OpenAPI gives a header only the
     *        `simple` style, and anything else asking to skip the encoding is
     *        a document this generator will not guess at.
     */
    public function serialize(string $name, string|array $value, string $style, bool $explode, bool $allowReserved = false, bool $percentEncoded = true): string
    {
        if (!$percentEncoded && $style !== 'simple') {
            throw new UnsupportedGeneration(sprintf('Only the simple style is emitted verbatim, not "%s"', $style));
        }

        return match ($style) {
            'simple' => $this->simple($value, $explode, ',', $allowReserved, keepEncoded: ',', percentEncoded: $percentEncoded),
            'label' => '.' . $this->simple($value, $explode, $explode ? '.' : ',', $allowReserved, encodeDots: true, keepEncoded: ','),
            'matrix' => $this->matrix($name, $value, $explode, $allowReserved),
            'form' => $this->form($name, $value, $explode, $allowReserved),
            'spaceDelimited' => $this->delimited($name, $value, ' ', '%20', $allowReserved),
            'pipeDelimited' => $this->delimited($name, $value, '|', '|', $allowReserved),
            'deepObject' => $this->deepObject($name, $value, $allowReserved),
            default => throw new UnsupportedGeneration(sprintf('Unsupported parameter style "%s"', $style)),
        };
    }

    /** @param string|list<string>|array<array-key, string> $value */
    private function simple(string|array $value, bool $explode, string $pairSeparator, bool $allowReserved, bool $encodeDots = false, string $keepEncoded = '', bool $percentEncoded = true): string
    {
        if (!$percentEncoded) {
            return $this->verbatimSimple($value, $explode, $pairSeparator);
        }
        if (is_string($value)) {
            return $this->encode($value, $allowReserved, $encodeDots, $keepEncoded);
        }
        if (array_is_list($value)) {
            return implode($pairSeparator, array_map(fn(string $item): string => $this->encode($item, $allowReserved, $encodeDots, $keepEncoded), $this->list($value)));
        }

        $parts = [];
        // PHP cannot keep a numeric-string array key as a string, so the cast
        // belongs here, where the name goes on the wire, and not where the map
        // was built.
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $this->encode((string) $key, $allowReserved, $encodeDots, $keepEncoded) . ($explode ? '=' : ',') . $this->encode($item, $allowReserved, $encodeDots, $keepEncoded);
        }

        return implode($explode ? $pairSeparator : ',', $parts);
    }

    /**
     * Refuses a header value that no HTTP field can carry.
     *
     * Percent-encoding used to make this unreachable: a CR or an LF in a case
     * came out as `%0D%0A` and travelled harmlessly. A header is written as
     * sent now (openapi-contract#66), so a case carrying one would be a
     * request-splitting payload if a PSR-7 implementation let it through, and
     * a raw `InvalidArgumentException` from whichever one is installed if it
     * did not. Generated values never reach here — the alphabet and
     * {@see ParameterSchemas::isHeaderSafe()} keep them inside a field value —
     * so this speaks to a hand-written case, in this package's own vocabulary.
     */
    public static function assertTransmittableHeader(string $name, string $value): void
    {
        if ($value !== '' && preg_match('/\A[\x21-\x7e\x80-\xff](?:[\x20-\x7e\x80-\xff]*[\x21-\x7e\x80-\xff])?\z/', $value) !== 1) {
            throw new UnsupportedGeneration(sprintf('Header "%s" carries a value no HTTP field can', $name));
        }
    }

    /**
     * The same shape as {@see simple()} with nothing escaped. The generated
     * value has already been kept clear of the delimiter and of the optional
     * whitespace around it ({@see ParameterSchemas::separatorOf()}), because
     * without an escape that is the only way a member survives the wire.
     *
     * @param string|list<string>|array<array-key, string> $value
     */
    private function verbatimSimple(string|array $value, bool $explode, string $pairSeparator): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return implode($pairSeparator, $this->list($value));
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $key . ($explode ? '=' : ',') . $item;
        }

        return implode($explode ? $pairSeparator : ',', $parts);
    }

    /**
     * Matrix segments are separated by ";" and its unexploded forms join with
     * ",", so neither may come back raw however permissive `allowReserved` is.
     *
     * @param string|list<string>|array<string, string> $value
     */
    private function matrix(string $name, string|array $value, bool $explode, bool $allowReserved): string
    {
        $keep = ';,';
        if (is_string($value)) {
            return ';' . $this->encode($name) . '=' . $this->encode($value, $allowReserved, keepEncoded: $keep);
        }
        if (array_is_list($value)) {
            $values = $this->list($value);
            if (!$explode) {
                return ';' . $this->encode($name) . '=' . implode(',', array_map(fn(string $item): string => $this->encode($item, $allowReserved, keepEncoded: $keep), $values));
            }

            return implode('', array_map(fn(string $item): string => ';' . $this->encode($name) . '=' . $this->encode($item, $allowReserved, keepEncoded: $keep), $values));
        }
        if (!$explode) {
            return ';' . $this->encode($name) . '=' . $this->simple($this->object($value), explode: false, pairSeparator: ',', allowReserved: $allowReserved, keepEncoded: $keep);
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = ';' . $this->encode((string) $key) . '=' . $this->encode($item, $allowReserved, keepEncoded: $keep);
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
                // The items are joined with "," here, so `allowReserved` must
                // not hand that same "," back raw: the contract would split one
                // item into two and the case would describe a request the
                // server never received.
                return $this->pair($name, implode(',', array_map(fn(string $item): string => $this->encode($item, $allowReserved, keepEncoded: ','), $values)), $allowReserved, valueIsEncoded: true);
            }

            return implode('&', array_map(fn(string $item): string => $this->pair($name, $item, $allowReserved), $values));
        }
        if (!$explode) {
            return $this->pair($name, $this->simple($this->object($value), explode: false, pairSeparator: ',', allowReserved: $allowReserved, keepEncoded: ','), $allowReserved, valueIsEncoded: true);
        }

        $parts = [];
        foreach ($this->object($value) as $key => $item) {
            $parts[] = $this->pair((string) $key, $item, $allowReserved);
        }

        return implode('&', $parts);
    }

    /**
     * @param string|list<string>|array<string, string> $value
     * @param non-empty-string $delimiter the separator as the value reads it
     * @param non-empty-string $wireDelimiter the separator as it goes on the wire;
     *        a raw space is not a legal URI character, so it travels encoded
     */
    private function delimited(string $name, string|array $value, string $delimiter, string $wireDelimiter, bool $allowReserved): string
    {
        if (is_string($value) || !array_is_list($value)) {
            throw new UnsupportedGeneration('Delimited query parameters require a list value');
        }
        $items = $this->list($value);
        foreach ($items as $item) {
            // The style has no escape for its own separator: an item carrying
            // one is unrepresentable, not merely awkward to encode.
            if (str_contains($item, $delimiter)) {
                throw new UnsupportedGeneration(sprintf('Delimited query parameter values cannot contain "%s"', $delimiter));
            }
        }

        return $this->pair($name, implode($wireDelimiter, array_map(fn(string $item): string => $this->encode($item, $allowReserved), $items)), $allowReserved, valueIsEncoded: true);
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

    /** @var list<string> */
    private const array RESERVED_ENCODED = ['%3A', '%2F', '%3F', '%5B', '%5D', '%40', '%21', '%24', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B'];

    /** @var list<string> */
    private const array RESERVED_RAW = [':', '/', '?', '[', ']', '@', '!', '$', "'", '(', ')', '*', '+', ',', ';'];

    /**
     * @param string $keepEncoded reserved characters this style uses as a
     *        separator, which stay percent-encoded however permissive
     *        `allowReserved` is — leaving one raw would not widen the wire but
     *        change what it says
     */
    private function encode(string $value, bool $allowReserved = false, bool $encodeDots = false, string $keepEncoded = ''): string
    {
        $encoded = rawurlencode($value);
        if ($encodeDots) {
            $encoded = str_replace('.', '%2E', $encoded);
        }
        if (!$allowReserved) {
            return $encoded;
        }
        $search = [];
        $replace = [];
        foreach (self::RESERVED_RAW as $index => $raw) {
            if (str_contains($keepEncoded, $raw)) {
                continue;
            }
            $search[] = self::RESERVED_ENCODED[$index];
            $replace[] = $raw;
        }

        return str_ireplace($search, $replace, $encoded);
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

    /**
     * The member map of an object parameter.
     *
     * The key type is `array-key` and not `string` on purpose: PHP normalizes
     * a numeric-string array key to an integer, so a member the document named
     * `"2020"` really does arrive as `int 2020`. Saying `string` here made the
     * casts that put those names back on the wire look redundant, and the
     * language cannot keep that promise anyway.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, string>
     */
    private function object(array $value): array
    {
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration('Parameter requires an object value');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($item)) {
                throw new UnsupportedGeneration('Parameter object keys and values must be strings');
            }
            // A numeric member name arrives as an integer array key. It is a
            // name the document wrote, not a malformed key — the cast that
            // matters is at the point of use, where it becomes a string
            // argument, not here where it stays an array key.
            $result = array_replace($result, [$key => $item]);
        }

        return $result;
    }
}
