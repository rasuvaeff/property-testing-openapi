<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapts a request-to-response callable to the transport contract.
 *
 * @api
 */
final readonly class CallableTransport implements TransportInterface
{
    /**
     * @param Closure(RequestInterface): ResponseInterface $sender
     */
    public function __construct(private Closure $sender) {}

    #[\Override]
    public function send(RequestInterface $request): ResponseInterface
    {
        $response = ($this->sender)($request);
        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException(sprintf(
                'Transport callable must return %s, got %s',
                ResponseInterface::class,
                get_debug_type($response),
            ));
        }

        return $response;
    }
}
