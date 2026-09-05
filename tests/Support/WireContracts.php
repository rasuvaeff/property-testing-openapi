<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Rasuvaeff\OpenApiContract\Contract;

/**
 * Operations whose parameters and bodies a PHP SAPI can represent, used to ask
 * the one question neither package can answer alone: does the application
 * behind the validator receive the value the generator recorded?
 *
 * The validator's opinion is not the oracle here — it is one of the three
 * things being compared. A case is generated, materialized, and sent through
 * the transport that mimics the SAPI; the value the handler is given has to be
 * the value the case wrote down. When it is not, one of the two readings of
 * the wire is wrong and the validator's agreement means nothing: a query `+`
 * decoded as a literal plus by the validator and as a space by the server was
 * exactly that, and it stood for the life of the package until this comparison
 * was made (openapi-contract#52).
 *
 * What PHP cannot represent is deliberately absent, and named here rather than
 * silently omitted: an exploded array parameter travels as repeated `name=`
 * pairs, and `parse_str()` keeps only the last, so no agreement is possible for
 * it without the `name[]=` spelling OpenAPI does not use. That shape is pinned
 * by its own test instead.
 */
final class WireContracts
{
    /** @var list<string> */
    public const array OPERATIONS = ['wire.get', 'wire.form'];

    /**
     * The same `/wire` operation with `q` narrowed to exactly one admissible
     * value. A validator that accepts a request against it has said which
     * string it read, which is what makes the comparison with the server's
     * reading a comparison rather than two independent opinions.
     */
    public static function readingContract(string $expected): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'wire', 'version' => '1'],
            'paths' => ['/wire' => ['get' => [
                'operationId' => 'wire.get',
                'parameters' => [
                    ['name' => 'q', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
                        'schema' => ['type' => 'string', 'enum' => [$expected]]],
                    ['name' => 'kind', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
                        'schema' => ['type' => 'string', 'minLength' => 1]],
                    ['name' => 'session', 'in' => 'cookie', 'required' => true, 'style' => 'form',
                        'schema' => ['type' => 'string', 'minLength' => 1]],
                ],
                'responses' => ['204' => []],
            ]]],
        ]);
    }

    public static function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'info' => ['title' => 'wire', 'version' => '1'],
            'paths' => [
                '/wire' => ['get' => [
                    'operationId' => 'wire.get',
                    'parameters' => [
                        // A scalar carries whatever the alphabet produces,
                        // including the characters the two readings disagreed
                        // about: a space, a plus, and the reserved set.
                        ['name' => 'q', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
                            'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12]],
                        // An exploded object puts each member under its own
                        // name, which is the one container shape a SAPI reads
                        // back exactly.
                        ['name' => 'filter', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true,
                            'schema' => ['type' => 'object', 'required' => ['kind'], 'additionalProperties' => false, 'properties' => [
                                'kind' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6],
                                'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6],
                            ]]],
                        ['name' => 'session', 'in' => 'cookie', 'required' => true, 'style' => 'form',
                            'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 10]],
                    ],
                    'responses' => ['204' => []],
                ]],
                '/wire/form' => ['post' => [
                    'operationId' => 'wire.form',
                    'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12],
                            'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                        ],
                    ]]]],
                    'responses' => ['204' => []],
                ]],
            ],
        ]);
    }
}
