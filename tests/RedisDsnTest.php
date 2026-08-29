<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\RedisDsn;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(RedisDsn::class)]
final class RedisDsnTest
{
    #[DataProvider('dsnProvider')]
    public function parsesHostPortAndPrefix(string $dsn, string $host, int $port, string $prefix): void
    {
        $parsed = RedisDsn::parse($dsn);

        Assert::same($parsed->host, $host);
        Assert::same($parsed->port, $port);
        Assert::same($parsed->prefix, $prefix);
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function dsnProvider(): iterable
    {
        yield 'host only' => ['redis://127.0.0.1', '127.0.0.1', 6379, 'property-testing:corpus:'];
        yield 'host and port' => ['redis://redis:6380', 'redis', 6380, 'property-testing:corpus:'];
        yield 'prefix in path' => ['redis://redis:6380/suite-a:', 'redis', 6380, 'suite-a:'];
        yield 'trailing slash' => ['redis://redis/', 'redis', 6379, 'property-testing:corpus:'];
        yield 'nested prefix' => ['redis://redis/team/suite:', 'redis', 6379, 'team/suite:'];
    }

    public function exposesPredisConnectionParameters(): void
    {
        Assert::same(
            RedisDsn::parse('redis://redis:6380/suite:')->toPredisParameters(),
            ['scheme' => 'tcp', 'host' => 'redis', 'port' => 6380],
        );
    }

    #[DataProvider('malformedProvider')]
    public function rejectsMalformedDsn(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);
            Assert::fail('Expected an invalid Redis DSN');
        } catch (\InvalidArgumentException $failure) {
            Assert::string($failure->getMessage())->contains('expected redis://host[:port][/key-prefix]');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedProvider(): iterable
    {
        yield 'no host' => ['redis://'];
        yield 'port without host' => ['redis://:6379'];
        yield 'prefix without host' => ['redis:///prefix:'];
        yield 'non-numeric port' => ['redis://host:notaport'];
    }

    #[DataProvider('credentialledProvider')]
    public function rejectsCredentialsWithoutEchoingThem(string $dsn): void
    {
        try {
            RedisDsn::parse($dsn);
            Assert::fail('Expected credentialled DSN to fail');
        } catch (\InvalidArgumentException $failure) {
            Assert::string($failure->getMessage())->contains('credentials');
            Assert::false(str_contains($failure->getMessage(), 's3cret'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function credentialledProvider(): iterable
    {
        yield 'user and password' => ['redis://user:s3cret@redis:6379'];
        yield 'password only' => ['redis://:s3cret@redis:6379'];
        yield 'user only' => ['redis://user@redis:6379'];
    }
}
