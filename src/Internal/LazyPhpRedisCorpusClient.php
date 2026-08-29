<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\Runner\Redis\CorpusClient;
use Rasuvaeff\PropertyTesting\Runner\Redis\PhpRedisCorpusClient;

/**
 * Opens the ext-redis connection only when the corpus is first used.
 *
 * @internal
 */
final class LazyPhpRedisCorpusClient implements CorpusClient
{
    private ?PhpRedisCorpusClient $client = null;

    public function __construct(
        private readonly RedisDsn $dsn,
    ) {}

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->client()->get($key);
    }

    #[\Override]
    public function compareAndSet(string $key, ?string $expected, ?string $document): bool
    {
        return $this->client()->compareAndSet($key, $expected, $document);
    }

    private function client(): PhpRedisCorpusClient
    {
        if ($this->client instanceof PhpRedisCorpusClient) {
            return $this->client;
        }

        $redis = new \Redis();
        $redis->connect($this->dsn->host, $this->dsn->port);

        return $this->client = new PhpRedisCorpusClient($redis);
    }
}
