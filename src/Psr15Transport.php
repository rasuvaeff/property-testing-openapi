<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartParser;

/**
 * Executes requests in-process through a PSR-15 request handler.
 *
 * The server request carries what the SAPI would have populated: query
 * parameters from the URI, cookie parameters from the `Cookie` header, the
 * parsed body of `application/x-www-form-urlencoded` and `multipart/form-data`
 * payloads (PHP's own `parse_str()` semantics for names), and uploaded files
 * for multipart parts with a filename when a stream factory and an uploaded
 * file factory are configured — without them file parts are left out of the
 * parsed body and no uploaded files are attached. The raw body stream is
 * always passed through, rewound.
 *
 * @api
 */
final readonly class Psr15Transport implements TransportInterface
{
    private ?\Closure $afterRequest;

    private MultipartParser $multipart;

    /** @param null|callable(): void $afterRequest */
    public function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $serverRequests,
        ?callable $afterRequest = null,
        private ?StreamFactoryInterface $streams = null,
        private ?UploadedFileFactoryInterface $uploadedFiles = null,
    ) {
        $this->afterRequest = $afterRequest === null ? null : \Closure::fromCallable($afterRequest);
        $this->multipart = new MultipartParser();
    }

    #[\Override]
    public function send(RequestInterface $request): ResponseInterface
    {
        try {
            $serverRequest = $this->serverRequests->createServerRequest(
                method: $request->getMethod(),
                uri: $request->getUri(),
            );
            foreach ($request->getHeaders() as $name => $values) {
                if (!is_string($name)) {
                    continue;
                }
                $serverRequest = $serverRequest->withHeader($name, $values);
            }
            $serverRequest = $serverRequest
                ->withQueryParams($this->pairs($request->getUri()->getQuery()))
                ->withCookieParams($this->cookieParams($request->getHeaderLine('Cookie')));

            return $this->handler->handle($this->withBody($serverRequest, $request));
        } finally {
            if ($this->afterRequest instanceof \Closure) {
                ($this->afterRequest)();
            }
        }
    }

    private function withBody(ServerRequestInterface $serverRequest, RequestInterface $request): ServerRequestInterface
    {
        $stream = $request->getBody();
        $contents = (string) $stream;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $serverRequest = $serverRequest->withBody($stream);
        $contentType = $request->getHeaderLine('Content-Type');
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType === 'application/x-www-form-urlencoded') {
            return $serverRequest->withParsedBody($this->pairs($contents));
        }
        if (!str_starts_with($mediaType, 'multipart/')) {
            return $serverRequest;
        }
        $fields = [];
        $files = [];
        foreach ($this->multipart->parse($contents, $contentType) as $part) {
            if ($part['filename'] === null) {
                $fields[] = rawurlencode($part['name']) . '=' . rawurlencode($part['value']);

                continue;
            }
            $file = $this->uploadedFile($part['value'], $part['filename'], $part['contentType']);
            if ($file instanceof UploadedFileInterface) {
                $files[$part['name']] = $file;
            }
        }

        return $serverRequest
            ->withParsedBody($this->pairs(implode('&', $fields)))
            ->withUploadedFiles($files);
    }

    private function uploadedFile(string $contents, string $filename, ?string $contentType): ?UploadedFileInterface
    {
        if (!$this->streams instanceof StreamFactoryInterface || !$this->uploadedFiles instanceof UploadedFileFactoryInterface) {
            return null;
        }

        return $this->uploadedFiles->createUploadedFile(
            stream: $this->streams->createStream($contents),
            size: strlen($contents),
            error: UPLOAD_ERR_OK,
            clientFilename: $filename,
            clientMediaType: $contentType,
        );
    }

    /**
     * `parse_str()` semantics, as the SAPI applies them to the query string
     * and to form bodies.
     *
     * @return array<array-key, mixed>
     */
    private function pairs(string $encoded): array
    {
        if ($encoded === '') {
            return [];
        }
        parse_str($encoded, $result);

        return $result;
    }

    /** @return array<string, string> */
    private function cookieParams(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $cookies[$name] = urldecode(trim($value));
        }

        return $cookies;
    }
}
