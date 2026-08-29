<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\Runner\Corpus;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;

/**
 * Resolves the property adapter's `PROPERTY_DB` convention.
 *
 * @internal
 */
final class CorpusFromEnv
{
    private const string SCHEME_PATTERN = '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#';

    /** @var array<string, Corpus> */
    private static array $cache = [];

    private function __construct() {}

    public static function resolve(): ?Corpus
    {
        $dsn = getenv('PROPERTY_DB');
        if ($dsn === false || $dsn === '') {
            return null;
        }

        return self::$cache[$dsn] ??= self::build($dsn);
    }

    private static function build(string $dsn): Corpus
    {
        if (preg_match(self::SCHEME_PATTERN, $dsn, $matches) !== 1) {
            return new FilesystemCorpus($dsn);
        }
        if (strtolower($matches[1]) !== 'redis') {
            throw new \InvalidArgumentException(sprintf(
                'PROPERTY_DB uses an unsupported scheme "%s://"; use redis:// for a shared corpus or a plain directory path for a local one',
                $matches[1],
            ));
        }

        $parsed = RedisDsn::parse($dsn);

        return new RedisCorpus(self::client($parsed, $dsn), $parsed->prefix);
    }

    private static function client(RedisDsn $dsn, string $raw): CorpusClient
    {
        if (extension_loaded('redis')) {
            return new LazyPhpRedisCorpusClient($dsn);
        }
        if (class_exists(\Predis\Client::class)) {
            return new PredisCorpusClient(new \Predis\Client($dsn->toPredisParameters()));
        }

        throw new \InvalidArgumentException(sprintf(
            'PROPERTY_DB="%s" needs a Redis client: install ext-redis or require predis/predis',
            $raw,
        ));
    }
}
