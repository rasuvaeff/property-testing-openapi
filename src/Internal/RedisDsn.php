<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * Parsed `redis://host[:port][/key-prefix]` corpus DSN.
 *
 * @internal
 */
final readonly class RedisDsn
{
    private const int DEFAULT_PORT = 6379;
    private const string DEFAULT_PREFIX = 'property-testing:corpus:';

    /**
     * @param non-empty-string $host
     * @param non-empty-string $prefix
     */
    private function __construct(
        public string $host,
        public int $port,
        public string $prefix,
    ) {}

    /** @return array{scheme: 'tcp', host: non-empty-string, port: int} */
    public function toPredisParameters(): array
    {
        return ['scheme' => 'tcp', 'host' => $this->host, 'port' => $this->port];
    }

    public static function parse(string $dsn): self
    {
        $parts = parse_url($dsn);
        if (is_array($parts) && (isset($parts['user']) || isset($parts['pass']))) {
            throw new \InvalidArgumentException(
                'PROPERTY_DB carries credentials in its userinfo, which is not supported; configure Redis AUTH out of band',
            );
        }

        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB="%s" is not a usable Redis DSN; expected redis://host[:port][/key-prefix]',
                $dsn,
            ));
        }

        $port = is_array($parts) ? ($parts['port'] ?? null) : null;
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        $prefix = is_string($path) ? ltrim($path, '/') : '';

        return new self(
            host: $host,
            port: is_int($port) ? $port : self::DEFAULT_PORT,
            prefix: $prefix === '' ? self::DEFAULT_PREFIX : $prefix,
        );
    }
}
