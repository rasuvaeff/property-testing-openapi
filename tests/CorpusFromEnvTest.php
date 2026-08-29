<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\CorpusFromEnv;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\LazyPhpRedisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\FilesystemCorpus;
use Rasuvaeff\PropertyTesting\Runner\Redis\PredisCorpusClient;
use Rasuvaeff\PropertyTesting\Runner\RedisCorpus;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(CorpusFromEnv::class)]
final class CorpusFromEnvTest
{
    public function unsetAndEmptyValuesDisableTheCorpus(): void
    {
        putenv('PROPERTY_DB');
        Assert::null(CorpusFromEnv::resolve());

        putenv('PROPERTY_DB=');

        try {
            Assert::null(CorpusFromEnv::resolve());
        } finally {
            putenv('PROPERTY_DB');
        }
    }

    public function aPathResolvesToAMemoizedFilesystemCorpus(): void
    {
        putenv('PROPERTY_DB=' . sys_get_temp_dir() . '/openapi-property-db');

        try {
            $corpus = CorpusFromEnv::resolve();
            Assert::instanceOf($corpus, FilesystemCorpus::class);
            Assert::same(CorpusFromEnv::resolve(), $corpus);
        } finally {
            putenv('PROPERTY_DB');
        }
    }

    public function redisResolvesLazilyAndKeepsTheDsnPrefix(): void
    {
        putenv('PROPERTY_DB=Redis://127.0.0.1:6399/openapi-suite:');

        try {
            $corpus = CorpusFromEnv::resolve();
            Assert::instanceOf($corpus, RedisCorpus::class);
            Assert::same((new \ReflectionProperty($corpus, 'prefix'))->getValue($corpus), 'openapi-suite:');
            Assert::instanceOf(
                (new \ReflectionProperty($corpus, 'client'))->getValue($corpus),
                extension_loaded('redis') ? LazyPhpRedisCorpusClient::class : PredisCorpusClient::class,
            );
        } finally {
            putenv('PROPERTY_DB');
        }
    }

    public function rejectsUnknownSchemesWithoutEchoingTheDsn(): void
    {
        putenv('PROPERTY_DB=rediss://secret.example.test:6379/openapi');

        try {
            CorpusFromEnv::resolve();
            Assert::fail('Expected unsupported corpus scheme to fail');
        } catch (\InvalidArgumentException $failure) {
            Assert::string($failure->getMessage())->contains('unsupported scheme "rediss://"');
            Assert::false(str_contains($failure->getMessage(), 'secret.example.test'));
        } finally {
            putenv('PROPERTY_DB');
        }
    }
}
