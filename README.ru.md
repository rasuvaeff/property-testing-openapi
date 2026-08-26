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
`query`, `headers`, `cookies` и optional JSON `body`. В нём нет credentials,
поэтому case можно сохранять в property corpus.

Неподдерживаемые schema assertions и non-JSON request bodies бросают
`UnsupportedGeneration`; они не расширяются молча до произвольных строк.

Runnable script находится в [examples](examples/README.md).
