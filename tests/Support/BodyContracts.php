<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;

/**
 * Form and multipart request-body fixtures shared between property bodies
 * and their generator providers.
 */
final class BodyContracts
{
    public static function multipart(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/upload' => ['post' => [
                'operationId' => 'upload.create',
                'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'required' => ['title', 'file'], 'properties' => [
                        'tags' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5]],
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12],
                        'file' => ['type' => 'string', 'format' => 'binary'],
                        'ids' => ['type' => 'array', 'uniqueItems' => true, 'minItems' => 2, 'maxItems' => 3, 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
                        'many' => ['type' => 'array', 'items' => ['type' => 'boolean']],
                        'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                        'flag' => ['type' => 'boolean'],
                    ]],
                    'encoding' => [
                        'title' => ['contentType' => 'text/markdown', 'headers' => [
                            'X-Malformed' => 'ignored',
                            7 => ['required' => true, 'example' => 'seven'],
                            'X-Unspecified' => ['example' => 'u'],
                            'X-Optional' => ['required' => false, 'example' => 'no'],
                            'X-Example' => ['required' => true, 'example' => 'yes'],
                            'X-Default' => ['required' => true, 'default' => 'd'],
                            'X-Bare' => ['required' => true],
                        ]],
                        'tags' => ['contentType' => 'text/plain', 'style' => 'form'],
                    ],
                ]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function multipartCase(): array
    {
        return ['case' => (new RequestCaseArbitrary())->forOperation(self::multipart()->operation('upload.create'))];
    }

    /**
     * The same body declared optional. The "body present" branch is the only
     * path that reads a generated multipart case back, and every other fixture
     * here declares the body required.
     */
    public static function optionalMultipart(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/maybe-upload' => ['post' => [
                'operationId' => 'upload.maybe',
                'requestBody' => ['required' => false, 'content' => ['multipart/form-data' => [
                    'schema' => ['type' => 'object', 'required' => ['title'], 'properties' => [
                        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12],
                        'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    ]],
                ]]],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function optionalMultipartCase(): array
    {
        return ['case' => (new RequestCaseArbitrary())->forOperation(self::optionalMultipart()->operation('upload.maybe'))];
    }

    public static function form(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/login' => ['post' => [
                'operationId' => 'login',
                'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => [
                    'schema' => ['type' => 'object', 'required' => ['user'], 'properties' => [
                        'user' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                        'age' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120],
                        'ratio' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'active' => ['type' => 'boolean'],
                        'tags' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4]],
                        'meta' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'maxLength' => 4], 'b' => ['type' => 'integer']]],
                    ]],
                    'encoding' => ['tags' => ['explode' => false], 'meta' => ['style' => 'form', 'explode' => true]],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function formCase(): array
    {
        return ['case' => (new RequestCaseArbitrary())->forOperation(self::form()->operation('login'))];
    }
}
