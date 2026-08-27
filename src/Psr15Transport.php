<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Executes requests in-process through a PSR-15 request handler.
 *
 * @api
 */
final readonly class Psr15Transport implements TransportInterface
{
    public function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $serverRequests,
    ) {}

    #[\Override]
    public function send(RequestInterface $request): ResponseInterface
    {
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

        return $this->handler->handle($serverRequest->withBody($request->getBody()));
    }
}
