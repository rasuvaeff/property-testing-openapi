<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends one already-materialized request to an explicit execution boundary.
 *
 * @api
 */
interface TransportInterface
{
    public function send(RequestInterface $request): ResponseInterface;
}
