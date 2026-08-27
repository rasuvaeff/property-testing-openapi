<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;
use Rasuvaeff\PropertyTesting\OpenApi\TransportInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;

#[Test]
#[Covers(TransportInterface::class)]
#[Covers(CallableTransport::class)]
#[Covers(Psr15Transport::class)]
final class TransportTest
{
    public function callableTransportPassesTheRequestAndReturnsResponse(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createRequest('GET', '/health');
        $expected = new Response(204);
        $transport = new CallableTransport(static function (RequestInterface $actual) use ($request, $expected): ResponseInterface {
            Assert::same($actual->getMethod(), $request->getMethod());
            Assert::same((string) $actual->getUri(), (string) $request->getUri());

            return $expected;
        });

        Assert::same($transport->send($request), $expected);
    }

    public function psr15TransportPreservesRequestTargetHeadersAndBody(): void
    {
        $factory = new Psr17Factory();
        $expected = new Response(201);
        $handler = Understudy::for(RequestHandlerInterface::class);
        expect(fn() => $handler->handle(Arg::satisfies(static fn(mixed $received): bool => $received instanceof ServerRequestInterface
            && $received->getMethod() === 'POST'
            && (string) $received->getUri() === 'https://api.example.test/pets?limit=2'
            && $received->getHeaderLine('X-Tenant') === 'public'
            && (string) $received->getBody() === '{"name":"Milo"}')))->returns($expected);
        $request = $factory->createRequest('POST', 'https://api.example.test/pets?limit=2')
            ->withHeader('X-Tenant', 'public')
            ->withBody($factory->createStream('{"name":"Milo"}'));

        $transport = new Psr15Transport($handler, $factory);

        Assert::same($transport->send($request), $expected);
    }
}
