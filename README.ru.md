# property-testing-openapi

Генераторы data-only OpenAPI request cases и materialization в PSR-7 для
`rasuvaeff/property-testing-core` и `rasuvaeff/openapi-contract`.

Текущий pre-release срез генерирует корректные scalar, array и object значения
из поддерживаемого подмножества JSON Schema, materialize-ит OpenAPI 3 parameter
styles и JSON request body, после чего request проверяется contract validator
до передачи transport.

## Установка

```bash
composer require --dev rasuvaeff/property-testing-openapi
```

Пока идёт pre-release разработка, `openapi-contract` резолвится через локальный
path repository из `composer.json`.

## Генерация и materialization

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray($document);
$operation = $contract->operation('pets.get');
$case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(42))->value;
$request = (new RequestMaterializer(new Psr17Factory(), new Psr17Factory()))->materialize($operation, $case);

$contract->validateRequest($request)->assertValid();
```

`RequestCaseData` - JSON-compatible associative array с раздельными `path`,
`query`, `headers`, `cookies` и optional JSON `body`. Required parameters и
request bodies присутствуют всегда; для optional parameters и JSON body
генерируются обе ветви - presence и absence. В нём нет credentials, поэтому
case можно сохранять в property corpus.

Для object schemas поддерживаются `minProperties`, `maxProperties` и boolean-
или schema-valued `additionalProperties` в пределах generation budget.

## Credentials для security

Security requirements наследуются операциями; явный пустой список `security`
означает anonymous access, а пустой requirement object (`{}`) - anonymous
alternative. `SecuritySelector` пробует alternatives в порядке
документа через `CredentialsProviderInterface`; provider может отклонить одну
alternative исключением `CredentialsUnavailable`:

```php
$selection = (new SecuritySelector())->select($operation, $credentials);
$request = (new RequestMaterializer($requests, $streams))->materialize(
    $operation,
    $case,
    $selection['credentials'] ?? null,
);
```

`Credentials` применяет headers, query и cookies только во время
materialization. Секреты не попадают в `RequestCaseData` и сохранённые property
examples.

`NegativeRequestCaseArbitrary` пока предоставляет одну безопасную negative
категорию: удаляет один обязательный path, query, header, cookie или body и
записывает `misuse: {kind: 'missing-required', ...}`. Такой request должен
отвергаться contract validation до вызова transport; остальные negative
категории появятся только вместе с отдельным invalidation oracle.

Неподдерживаемые schema assertions и non-JSON request bodies бросают
`UnsupportedGeneration`; они не расширяются молча до произвольных строк.

## Transports

Исполнение всегда явно задаётся пользователем. Для closure используется
`CallableTransport`, для in-process PSR-15 handler - `Psr15Transport`:

```php
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;

$transport = new Psr15Transport($handler, $serverRequestFactory);
$response = $transport->send($request);
```

Transport не выполняет сетевой I/O за пределами переданного callable или
PSR-15 boundary. Исключение transport пробрасывается вызывающему коду;
будущий suite layer будет классифицировать его отдельно от contract
violations.

Runnable script находится в [examples](examples/README.md).
