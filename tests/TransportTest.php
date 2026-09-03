<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartParser;
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\BodyContracts;
use Rasuvaeff\PropertyTesting\OpenApi\TransportInterface;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;

#[Test]
#[Covers(TransportInterface::class)]
#[Covers(CallableTransport::class)]
#[Covers(Psr15Transport::class)]
#[Covers(MultipartParser::class)]
final class TransportTest
{
    public function psr15TransportPopulatesQueryAndCookieParamsLikeTheSapi(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->recorder();
        $request = $factory->createRequest('GET', '/pets?limit=2&tags[]=a&tags[]=b%20c&filter[state]=on&flag')
            ->withHeader('Cookie', 'session=abc%20d; theme= dark ;=skipped; bare');

        (new Psr15Transport($handler, $factory))->send($request);

        $seen = $handler->request;
        Assert::instanceOf($seen, ServerRequestInterface::class);
        Assert::same($seen->getQueryParams(), ['limit' => '2', 'tags' => ['a', 'b c'], 'filter' => ['state' => 'on'], 'flag' => '']);
        Assert::same($seen->getCookieParams(), ['session' => 'abc d', 'theme' => 'dark', 'bare' => '']);
        Assert::same($seen->getParsedBody(), null);
        Assert::same($seen->getUploadedFiles(), []);
    }

    public function psr15TransportParsesFormBodiesAndRewindsTheStream(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->recorder();
        $body = $factory->createStream('user=ann&tags[]=x&tags[]=y&meta[a]=1');
        $body->seek(4);
        $request = $factory->createRequest('POST', '/login')
            ->withHeader('Content-Type', 'Application/X-WWW-Form-Urlencoded ; charset=utf-8')
            ->withBody($body);

        (new Psr15Transport($handler, $factory))->send($request);

        $seen = $handler->request;
        Assert::instanceOf($seen, ServerRequestInterface::class);
        Assert::same($seen->getParsedBody(), ['user' => 'ann', 'tags' => ['x', 'y'], 'meta' => ['a' => '1']]);
        Assert::same($seen->getBody()->tell(), 0);
        Assert::same($seen->getBody()->getContents(), 'user=ann&tags[]=x&tags[]=y&meta[a]=1');
        Assert::same($seen->getQueryParams(), []);
    }

    public function psr15TransportParsesMultipartFieldsAndFilesWhenFactoriesAreConfigured(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->recorder();
        $payload = "preamble\r\n--b1\r\n"
            . "Content-Disposition: form-data; name=\"title\"\r\nContent-Type: text/plain\r\n\r\nhello\r\n"
            . "--b1\r\n"
            . "Content-Disposition: form-data; name=\"tags[]\"\r\n\r\none\r\n"
            . "--b1\r\n"
            . "Content-Disposition: form-data; name=tags[]\r\n\r\ntwo\r\n"
            . "--b1\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"a \\\"q\\\".bin\"\r\nContent-Type: application/octet-stream\r\n\r\n\x00\xFF\r\n"
            . "--b1\r\n"
            . "Content-Disposition: attachment\r\n\r\nno name\r\n"
            . "--b1\r\n"
            . "broken part without a blank line\r\n"
            . "--b1--\r\n";
        $request = $factory->createRequest('POST', '/upload')
            ->withHeader('Content-Type', 'multipart/form-data; BOUNDARY="b1"')
            ->withBody($factory->createStream($payload));

        (new Psr15Transport($handler, $factory, null, $factory, $factory))->send($request);

        $seen = $handler->request;
        Assert::instanceOf($seen, ServerRequestInterface::class);
        Assert::same($seen->getParsedBody(), ['title' => 'hello', 'tags' => ['one', 'two']]);
        $files = $seen->getUploadedFiles();
        Assert::same(array_keys($files), ['file']);
        $file = $files['file'];
        Assert::instanceOf($file, UploadedFileInterface::class);
        Assert::same($file->getClientFilename(), 'a "q".bin');
        Assert::same($file->getClientMediaType(), 'application/octet-stream');
        Assert::same($file->getSize(), 2);
        Assert::same((string) $file->getStream(), "\x00\xFF");
        Assert::same((string) $seen->getBody(), $payload);
    }

    public function psr15TransportLeavesFilePartsOutWithoutFactories(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->recorder();
        $payload = "--b1\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nhello\r\n"
            . "--b1\r\nContent-Disposition: form-data; name=\"file\"; filename=\"a.bin\"\r\n\r\nxx\r\n--b1--\r\n";
        $request = $factory->createRequest('POST', '/upload')
            ->withHeader('Content-Type', 'multipart/form-data; boundary=b1')
            ->withBody($factory->createStream($payload));

        foreach ([new Psr15Transport($handler, $factory), new Psr15Transport($handler, $factory, null, $factory), new Psr15Transport($handler, $factory, null, null, $factory)] as $transport) {
            $transport->send($request);

            $seen = $handler->request;
            Assert::instanceOf($seen, ServerRequestInterface::class);
            Assert::same($seen->getParsedBody(), ['title' => 'hello']);
            Assert::same($seen->getUploadedFiles(), []);
        }
    }

    public function multipartParserReadsNothingWithoutABoundaryOrADelimiter(): void
    {
        $parser = new MultipartParser();

        Assert::same($parser->parse("--b1\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n--b1--\r\n", 'multipart/form-data'), []);
        Assert::same($parser->parse("--\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1\r\n----\r\n", 'multipart/form-data'), []);
        Assert::same($parser->parse('no delimiter here', 'multipart/form-data; boundary=b1'), []);
        Assert::same($parser->parse("--b1 trailing garbage", 'multipart/form-data; boundary=b1'), []);
        Assert::same($parser->parse("--b1\r\nContent-Disposition: form-data; name=\"a\"\r\n\r\n1", 'multipart/form-data; boundary=b1'), []);
        Assert::same(
            $parser->parse("--b1\r\nContent-Disposition: form-data; name=\"a\"\r\nContent-Type: text/plain\r\n\r\n1\r\n--b1--", 'multipart/form-data; boundary=b1'),
            [['name' => 'a', 'filename' => null, 'contentType' => 'text/plain', 'value' => '1']],
        );
        Assert::same(
            $parser->parse("--b1\r\nContent-Disposition: form-data; name=\"\"\r\n\r\nContent-Disposition: form-data; name=\"z\"\r\nContent-Type: text/html\r\n--b1--", 'multipart/form-data; boundary=b1'),
            [['name' => '', 'filename' => null, 'contentType' => null, 'value' => "Content-Disposition: form-data; name=\"z\"\r\nContent-Type: text/html"]],
        );
    }

    /**
     * Every generated multipart case reaches an in-process handler the way
     * the SAPI would deliver it: scalar parts as fields, the binary part as
     * an uploaded file.
     *
     * @param array{operationKey: string, path: array<string, string|list<string>|array<string, string>>, query: array<string, string|list<string>|array<string, string>>, headers: array<string, string|list<string>|array<string, string>>, cookies: array<string, string|list<string>|array<string, string>>, body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed}, misuse: null} $case
     */
    #[Property(runs: 60, generators: [BodyContracts::class, 'multipartCase'])]
    public function psr15TransportDeliversGeneratedMultipartCasesAsFieldsAndFiles(array $case): void
    {
        $factory = new Psr17Factory();
        $operation = BodyContracts::multipart()->operation('upload.create');
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);
        $handler = $this->recorder();

        (new Psr15Transport($handler, $factory, null, $factory, $factory))->send($request);

        $seen = $handler->request;
        Assert::instanceOf($seen, ServerRequestInterface::class);
        $parsed = $seen->getParsedBody();
        Assert::true(is_array($parsed));
        $parts = $case['body']['parts'] ?? [];
        $expectedTitle = null;
        $expectedFile = null;
        foreach ($parts as $part) {
            if ($part['name'] === 'title') {
                $expectedTitle = $part['value'];
            }
            if ($part['name'] === 'file') {
                $expectedFile = base64_decode($part['value'], strict: true);
            }
        }
        Assert::same(is_array($parsed) ? ($parsed['title'] ?? null) : null, $expectedTitle);
        Assert::true(is_array($parsed) && !array_key_exists('file', $parsed));
        $files = $seen->getUploadedFiles();
        Assert::same(array_keys($files), ['file']);
        Assert::true($files['file'] instanceof UploadedFileInterface && (string) $files['file']->getStream() === $expectedFile);
        Assert::true($files['file'] instanceof UploadedFileInterface && $files['file']->getClientFilename() === 'file');
    }

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

    public function psr15TransportResetsStateOnceAfterAResponse(): void
    {
        $factory = new Psr17Factory();
        $handler = Understudy::for(RequestHandlerInterface::class);
        expect(fn() => $handler->handle(Arg::any()))->returns(new Response(204));
        $resets = 0;
        $transport = new Psr15Transport(
            $handler,
            $factory,
            afterRequest: static function () use (&$resets): void {
                ++$resets;
            },
        );

        $transport->send($factory->createRequest('GET', '/health'));

        Assert::same($resets, 1);
    }

    public function psr15TransportResetsStateOnceWhenTheHandlerThrows(): void
    {
        Expect::exception(\RuntimeException::class);
        $factory = new Psr17Factory();
        $handler = Understudy::for(RequestHandlerInterface::class);
        expect(fn() => $handler->handle(Arg::any()))->throws(new \RuntimeException('handler failed'));
        $resets = 0;
        $transport = new Psr15Transport(
            $handler,
            $factory,
            afterRequest: static function () use (&$resets): void {
                ++$resets;
            },
        );

        try {
            $transport->send($factory->createRequest('GET', '/health'));
        } finally {
            Assert::same($resets, 1);
        }
    }

    /** @return RequestHandlerInterface&object{request: null|ServerRequestInterface} */
    private function recorder(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(204);
            }
        };
    }
}
