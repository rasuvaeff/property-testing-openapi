<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Rasuvaeff\OpenApiContract\Contract;

/**
 * The document both readers claim to support, kept deliberately smaller than
 * {@see ZooContracts}.
 *
 * A differential is only informative on the intersection of what the two
 * implementations promise. Feeding league the zoo would produce a long list of
 * pinned league limitations — 3.1 type unions, `deepObject`, `pipeDelimited`,
 * `allowReserved`, `encoding.contentType` — and that list is exactly the
 * hand-written corpus this package exists to escape. What is left here is the
 * overlap: OAS 3.0, plain scalars and containers, the four body encodings both
 * decode, and the parameter styles both deserialize.
 *
 * Named exclusions, each for a reason rather than an oversight:
 *
 * - `format`: opis asserts it, cebe treats it as an annotation. The divergence
 *   is deliberate on this side and already pinned in the contract's own
 *   differential; generating format misuse here would only re-report it 240
 *   times per run.
 * - An exploded array query parameter: it travels as repeated `name=` pairs and
 *   league reads its query from `getQueryParams()`, so `parse_str()` keeps only
 *   the last. {@see \Rasuvaeff\PropertyTesting\OpenApi\Tests\SapiAgreementTest::anExplodedArrayIsUnreadableByAPhpSapi()}
 *   pins why no reading can agree there.
 * - A cookie parameter: league reads the `Cookie` header verbatim, without
 *   percent-decoding, so any generated value carrying a reserved character is a
 *   disagreement about cookie encoding rather than about the document.
 * - A header parameter: the same disagreement, and a sharper one, because RFC
 *   6570 does say what `style: simple` means. It is pinned as its own case in
 *   {@see \Rasuvaeff\PropertyTesting\OpenApi\Tests\Differential\LeagueGeneratedDifferentialTest::aPercentEncodedHeaderIsReadByOnlyOneOfThem()}
 *   rather than re-reported by every generated run that happens to draw one.
 */
final class DifferentialContracts
{
    /** @var list<string> */
    public const array OPERATIONS = ['items.get', 'items.create', 'forms.create', 'uploads.create'];

    private static ?Contract $contract = null;

    public static function contract(): Contract
    {
        // Compiling the document is the expensive half of either reader, and
        // the differential asks both of them for a verdict several hundred
        // times per run.
        return self::$contract ??= Contract::fromArray(self::document());
    }

    /**
     * The header parameter the generated traffic stays away from, kept as its
     * own one-operation document so the pinned divergence is asserted without
     * putting a header parameter in every generated case.
     */
    public static function headerContract(): Contract
    {
        return Contract::fromArray(self::headerDocument());
    }

    /** @return array<string, mixed> */
    public static function headerDocument(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'differential-header', 'version' => '1'],
            'paths' => ['/trace' => ['get' => [
                'operationId' => 'trace.get',
                'parameters' => [
                    ['name' => 'X-Trace', 'in' => 'header', 'required' => true,
                        'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8]],
                ],
                'responses' => ['200' => ['description' => 'ok']],
            ]]],
        ];
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'differential', 'version' => '1'],
            'paths' => [
                '/items/{id}' => ['get' => [
                    'operationId' => 'items.get',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true,
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 9999]],
                        ['name' => 'limit', 'in' => 'query', 'required' => true,
                            'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        // Required on purpose: the negative arbitraries build
                        // a misuse only out of a parameter whose absence is
                        // not itself the violation.
                        ['name' => 'q', 'in' => 'query', 'required' => true,
                            'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 12]],
                        ['name' => 'kind', 'in' => 'query', 'required' => true,
                            'schema' => ['type' => 'string', 'enum' => ['cat', 'dog']]],
                        ['name' => 'ref', 'in' => 'query', 'required' => true,
                            'schema' => ['type' => 'string', 'pattern' => '^[a-z]{2,6}$']],
                        // The one container shape a SAPI reads back exactly:
                        // each member arrives under its own name.
                        ['name' => 'filter', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true,
                            'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                                'tag' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                                'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4],
                            ]]],
                    ],
                    'responses' => ['200' => ['description' => 'ok']],
                ]],
                '/items' => ['post' => [
                    'operationId' => 'items.create',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['name', 'kind'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                            'kind' => ['type' => 'string', 'enum' => ['cat', 'dog']],
                            'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9],
                            'nested' => [
                                'type' => 'object',
                                'required' => ['slug'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'slug' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6],
                                    'flags' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'string', 'maxLength' => 3]],
                                ],
                            ],
                        ],
                    ]]]],
                    'responses' => ['201' => ['description' => 'created']],
                ]],
                '/forms' => ['post' => [
                    'operationId' => 'forms.create',
                    'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'additionalProperties' => false,
                        'properties' => [
                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12],
                            'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                        ],
                    ]]]],
                    'responses' => ['204' => ['description' => 'no content']],
                ]],
                '/uploads' => ['post' => [
                    'operationId' => 'uploads.create',
                    'requestBody' => ['required' => true, 'content' => ['multipart/form-data' => ['schema' => [
                        'type' => 'object',
                        'required' => ['note'],
                        'additionalProperties' => false,
                        'properties' => [
                            'note' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8],
                            'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6],
                        ],
                    ]]]],
                    'responses' => ['201' => ['description' => 'created']],
                ]],
            ],
        ];
    }
}
