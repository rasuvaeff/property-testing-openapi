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
    private ?\Closure $afterRequest;

    /** @param null|callable(): void $afterRequest */
    public function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $serverRequests,
        ?callable $afterRequest = null,
    ) {
        $this->afterRequest = $afterRequest === null ? null : \Closure::fromCallable($afterRequest);
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

            return $this->handler->handle($serverRequest->withBody($request->getBody()));
        } finally {
            if ($this->afterRequest instanceof \Closure) {
                ($this->afterRequest)();
            }
        }
    }
}
