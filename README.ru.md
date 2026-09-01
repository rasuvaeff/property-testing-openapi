# property-testing-openapi

Генераторы data-only OpenAPI request cases и materialization в PSR-7 для
`rasuvaeff/property-testing-core` и `rasuvaeff/openapi-contract`.

Текущий pre-release срез генерирует корректные scalar, array и object значения
из поддерживаемого подмножества JSON Schema, materialize-ит OpenAPI 3 parameter
styles и JSON, form-urlencoded или multipart request body, после чего request проверяется contract validator
до передачи transport.

## Установка

```bash
composer require --dev rasuvaeff/property-testing-openapi
```

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
`query`, `headers`, `cookies` и optional `body`. Form body хранит logical value,
а multipart body - deterministic boundary и data-only parts; binary payload
представлен как base64. Части multipart — только скаляры и binary: вложенные
объекты и массивы падают как `UnsupportedGeneration`; content type части —
умолчание OAS для схемы элемента, если Encoding Object не задал свой.
Required parameters и
request bodies присутствуют всегда; для optional parameters и JSON body
генерируются обе ветви - presence и absence. В нём нет credentials, поэтому
case можно сохранять в property corpus.

У пустого массива или объекта нет form-style представления на проводе
(RFC 6570 считает его неопределённым), поэтому materializer опускает такой
parameter или form-свойство, а генератор строит required array/object
parameters и required form-свойства непустыми.

Для object schemas поддерживаются `minProperties`, `maxProperties` и boolean-
или schema-valued `additionalProperties` в пределах generation budget.

Request target строится по первому effective server операции (приоритет
operation > path > root, server variables подставлены значениями по умолчанию
на стороне `openapi-contract`): относительный server вроде `/api/v1` даёт
path-only URI, который остаётся host-agnostic, абсолютный — абсолютный URI
(`https://api.example.com:8443/v2/pets/42`). `withBaseUri()` подменяет
объявленный server явным `scheme://host[:port][/base]` или root-relative
`/base` для окружения потребителя; последнее слово остаётся за контрактом:
абсолютный override, противоречащий всем объявленным servers, не матчится
молча, а даёт `request.server.mismatch` при валидации. Credentials
применяются после выбора URI и не могут изменить scheme или host.

```php
$local = (new RequestMaterializer($factory, $factory))->withBaseUri('/v1');
```

Конструктивное подмножество `not` поддерживает исключения через `const`, `enum`
и `type`; остальные negative assertions остаются fail-closed с
`UnsupportedGeneration`.

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

`Credentials` принимает обычную строку или список строк для каждого значения
header, query и cookie. Публичные maps нормализуются в списки. Credentials
применяются только во время materialization, поэтому секреты не попадают в
`RequestCaseData` и сохранённые property examples:

```php
$credentials = new Credentials(
    headers: ['Authorization' => 'Bearer token'],
    query: ['tenant' => ['public', 'fallback']],
);
```

`NegativeRequestCaseArbitrary` предоставляет конструктивные negative-категории.
`forOperation()` удаляет один обязательный path, query, header, cookie или body
и записывает `misuse.kind = 'missing-required'`.
`typeMismatchForOperation()` заменяет один обязательный scalar-параметр типа
`integer`/`number`/`boolean`/`null` заведомо неверным wire-значением и записывает
`misuse.kind = 'type'`. `enumMismatchForOperation()` аналогично выбирает
значение вне scalar enum и записывает `misuse.kind = 'enum'`, а
`constMismatchForOperation()` — значение, отличное от обязательного scalar
`const`, с `misuse.kind = 'const'`.
`boundaryMismatchForOperation()` заменяет один обязательный
`integer`/`number`-параметр wire-значением сразу за границей
`minimum`/`maximum` (с учётом boolean exclusive границ) и записывает
`misuse.kind = 'boundary'`.
`lengthMismatchForOperation()` заменяет один обязательный string-параметр
значением с длиной сразу за границей `minLength`/`maxLength` и записывает
`misuse.kind = 'length'`; параметры с `enum`, `const`, `pattern` или `format`
пропускаются — чистое нарушение длины там не гарантируется.
`formatMismatchForOperation()` заменяет один обязательный string-параметр
фиксированным значением, доказуемо нарушающим его `format` (`uuid`, `email`,
`ipv4`, `uri`, `uri-reference`, `date`, `date-time`), и записывает
`misuse.kind = 'format'`; `url` исключён — validation backend его не
ассертит.
`patternMismatchForOperation()` заменяет один обязательный string-параметр
найденным поиском значением, доказуемо нарушающим его `pattern`, и записывает
`misuse.kind = 'pattern'`. Оракул — сам pattern: ограниченные кандидаты
(образцы из алфавита и мутации принятого значения) проверяются `preg_match()`
с тем же regex, который компилирует validation backend. Параметры с `enum`,
`const` или `format` пропускаются, witness остаётся внутри окна
`minLength`/`maxLength`, а ошибка PCRE или исчерпанный бюджет
кандидатов/времени отвергаются fail-closed, без угадывания.
`additionalPropertyForOperation()` добавляет одно необъявленное свойство в
обязательное JSON object body со схемой `additionalProperties: false` и
записывает `misuse.kind = 'additional-properties'` с именем добавленного
свойства.
`mediaTypeMismatchForOperation()` оставляет schema-валидное JSON body, но
отправляет его с необъявленным Content-Type и записывает
`misuse.kind = 'media-type'`; операции с wildcard media types отвергаются
fail-closed.
`malformedJsonForOperation()` заменяет обязательное JSON body сырым
malformed-payload (`encoding: 'raw'`) под объявленным media type и записывает
`misuse.kind = 'json-syntax'`. Такие request
должны отвергаться contract validation до
вызова transport; остальные negative-категории появятся только вместе с
отдельным invalidation oracle.

Неподдерживаемые schema assertions и non-JSON request bodies бросают
`UnsupportedGeneration`; они не расширяются молча до произвольных строк.

## Transports

Исполнение всегда явно задаётся пользователем. Для closure используется
`CallableTransport`, для in-process PSR-15 handler - `Psr15Transport`:

```php
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;

$transport = new Psr15Transport(
    $handler,
    $serverRequestFactory,
    afterRequest: static fn() => $stateResetter->reset(),
);
$response = $transport->send($request);
```

Transport не выполняет сетевой I/O за пределами переданного callable или
PSR-15 boundary. Исключение transport пробрасывается вызывающему коду без
преобразования. Необязательный hook `afterRequest` вызывается ровно один раз в
`finally`, в том числе когда handler бросает исключение. Для Yii worker передайте
через hook вызов `Yiisoft\Di\StateResetter::reset()`, переиспользуя загруженные
runner и handler; не создавайте runner для каждого сгенерированного request.

## Suite

`ContractSuite` — framework-neutral модель suite: явный выбор операций,
transport, optional credentials и встроенные per-trial checks.

```php
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;

$suite = ContractSuite::fromContract($contract, $requestFactory, $streamFactory)
    ->operations(['pets.get'])
    ->transport(new Psr15Transport($handler, $serverRequestFactory));

$case = $suite->validCases('pets.get')->generate(new Random(42))->value;
$suite->checkValid('pets.get', $case);
```

Selection по умолчанию пуст. `operations()` добавляет явные operation keys,
`allSafeOperations()` — opt-in для всех GET/HEAD операций, `exclude()` удаляет
ключи. Операция с unsafe HTTP-методом должна быть указана явно **и** включена
через `allowUnsafeOperations()`; selection с unsafe операцией без этого gate
бросает `SuiteConfigurationError`, а не фильтрует её молча.

`baseUri()` materialize-ит каждый request против явного base URI вместо
объявленного server операции — root-relative `/v1` оставляет in-process
запросы host-agnostic, когда документ объявляет production host, а абсолютный
override обязан совпадать с объявленным server, иначе `checkValid()` падает
с `request.server.mismatch` ещё до transport.

`checkValid()` materialize-ит case (применяя credentials, выбранные через
настроенный `CredentialsProviderInterface`), требует валидности request до
transport, отправляет его и падает с `CheckFailed` на 5xx статусе или
неконформном exchange. `checkNegative()` требует `misuse` metadata и
invalid-статус case до transport, после чего проверяет, что invalid input не
приводит к 5xx. Более строгий oracle — opt-in `RejectionPolicy`: из OpenAPI
`invalid -> 4xx` не следует:

`CheckFailed`, брошенный из-за validation result, хранит его в `$result` и
рендерит в сообщении каждое нарушение через `ValidationResultFormatter` из
`openapi-contract` — operation, code, location, instance path, spec pointer,
ограниченные expected/actual — детерминированно, с редактированием actual
для header, cookie, query и secret-подобных путей. `OperationPropertyFailed`
несёт этот текст как причину, так что упавшая фаза показывает полную
диагностику без разбора объектов:

```text
Exchange for operation "pets.get" violates the contract
OpenAPI contract validation failed with 1 violation(s)
1. code: "response.body.schema"
   operation: "pets.get"
   location: "body"
   instancePath: "/name"
   specPointer: "/components/schemas/Pet"
   expected: {"type":"string"}
   actual: null
   message: "..."
```

```php
use Rasuvaeff\PropertyTesting\OpenApi\RejectionPolicy;

$suite = $suite->rejectionPolicy(
    RejectionPolicy::rejectWith('4XX')->forOperation('legacy.get', 200),
);
```

С настроенной policy `checkNegative()` дополнительно падает, когда статус
ответа не совпадает ни с default selectors, ни с per-operation override
(точные коды или `NXX`-диапазоны).

`negativeCases()` — взвешенный выбор среди всех negative-категорий,
конструктивно поддержанных операцией; операция без единой constructible
категории бросает `UnsupportedGeneration`.

`exampleCases()` строит именованные валидные cases из собственных
`example`/`examples` документа (`DocumentExamples`): каждый parameter и
request body отдают свои именованные примеры (map `examples` из Example
Objects с `value`) и один безымянный (`example`, затем `example` Schema
Object, затем первый элемент его списка `examples`). На каждое уникальное
имя примера по всем частям строится один case, плюс case с именем `example`,
если хоть одна часть объявляет безымянный пример; часть без примера под
данным именем берёт свой безымянный, а без него — значение детерминированного
базового case с фиксированным seed. Результат одинаков при любом
`PROPERTY_SEED`, JSON-compatible и не содержит credentials. Example Objects
только с `externalValue` и multipart body ничего не дают; пример, который
нельзя представить как wire value (вложенный объект в parameter), бросает
`UnsupportedGeneration`. Cases здесь не валидируются — пример, нарушающий
собственную схему, падает в `checkValid()` до transport как любой другой
case: диагностируемый дефект документа, а не молча пропущенный пример.

`reproduce()` рендерит один case как redacted curl-команду. Credentials там
не применяются никогда, поэтому секреты provider-а не могут утечь по
построению; `RedactionPolicy` дополнительно редактирует названные headers,
query-параметры, cookies и dot-separated JSON body paths поверх дефолтного
набора заголовков (`Authorization`, `Proxy-Authorization`, `Cookie`,
`Set-Cookie`). Body preview ограничен по байтам и никогда не режет UTF-8
последовательность пополам.

```php
use Rasuvaeff\PropertyTesting\OpenApi\RedactionPolicy;

echo $suite->reproduce('pets.get', $case, new RedactionPolicy(bodyPaths: ['owner.card']));
```

## Генерация responses

Для тестирования API-клиента без живого трафика пакет генерирует ответы,
которые документированный провайдер может вернуть — и те, которые не должен:

```php
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;

$case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(42))->value;
$response = (new ResponseMaterializer($responseFactory, $streamFactory))->materialize($operation, $case);
$contract->validateResponse('payments.get', $response)->assertValid();
```

`ResponseCaseArbitrary::forOperation()` принимает явный конкретный статус;
Response Object — тот, к которому его резолвит контракт (точный код, затем
`NXX`, затем `default` — тот же выбор, что в `validateResponse()`, через
`Operation::responseFor()`). Required response headers присутствуют всегда,
optional берут обе ветви, JSON body генерируется без `writeOnly`-свойств.
`ResponseCaseData` JSON-compatible и corpus-safe, как и request-аналог.
Необъявленный статус, required header без схемы и body без JSON media type
падают как `UnsupportedGeneration`.

`NegativeResponseCaseArbitrary` конструктивно мутирует валидный case:
`undeclared-status`, `missing-required` header и body-категории
`missing-required`, `type`, `enum`, `const`, `boundary`, `length`, `pattern`
(через тот же bounded `preg_match()`-оракул, что и у requests),
`additional-properties`, `media-type`, `json-syntax` — на top-level
свойствах объекта или на скалярном/массивном корне body (`misuse.name` =
`$`). `forOperation()` — взвешенное объединение всех конструируемых
категорий. Каждый case доказуемо невалиден по
`Contract::validateResponse()`, `misuse` переживает shrinking, категория без
конструируемого witness падает fail-closed. Валидные cases прогоняйте через
decoder клиента (documented optional fields, enum states), невалидные —
чтобы доказать, что клиент отвергает malformed provider payload, а не
маппит его.

## Интеграция с test runner'ами

`OperationProperty` — framework-neutral runner-поверхность: операция — это
отдельный test case, а каждый сгенерированный request — property trial. Она
работает без изменений под Testo и PHPUnit, потому что ни одной стороне не
нужны framework-хуки: data provider — обычный статический метод, а проверка —
обычный вызов:

```php
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiOperations;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;

#[Test]
final class ApiContractTest
{
    #[DataProvider('operations')]
    public function operationConforms(string $operationKey): void
    {
        OperationProperty::check(self::suite(), $operationKey, runs: 100);
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        return OpenApiOperations::keys(self::suite());
    }
}
```

`check()` всегда выполняет valid-фазу, а negative-фазу — когда у операции
есть хотя бы одна конструктивная misuse-категория. Falsified-фаза бросает
`OperationPropertyFailed` с ключом операции, фазой, seed, shrunk minimal case
и redacted curl-репродьюсером.

Валидная фаза начинается с примеров документа (`exampleCases()`): они
выполняются до replay корпуса и random-фазы при любом seed и числе прогонов,
поэтому point fault, описанный самим документом — один конкретный id, на
котором сервер падает, — находится на первом прогоне, а не по везению.
Упавший пример бросает `OperationPropertyFailed` с его именем в `$example`,
case без shrinking, `runsBeforeFailure` равным нулю и тем же curl reproducer:

```text
Operation "pets.get" failed the valid phase on document example "legacy": Operation "pets.get" responded with server error status 500
Case: {"operationKey":"pets.get","path":{"id":"7"},...}
Reproduce: curl -X GET '/pets/7?verbose=true'
```

Паритет проверяется в CI: `composer build` гоняет PHPUnit-фикстуру
(`tests/PhpUnit/`, `composer test:phpunit`) рядом с Testo-сьютом; фикстура
проходит без изменений на PHPUnit 11.5, 12 и 13.

Environment-паритет с адаптерами property-testing: `PROPERTY_RUNS`
переопределяет число прогонов, `PROPERTY_SEED` фиксирует seed, если явный
аргумент `seed:` не передан (закреплённый seed также отключает corpus
replay), а `PROPERTY_DB` задаёт directory-backed regression corpus или общий
Redis corpus. Формат DSN: `redis://host[:port][/key-prefix]`, prefix по умолчанию
`property-testing:corpus:`. Нужен `ext-redis` или `predis/predis`; соединение
открывается лениво, а неизвестные схемы завершаются fail-closed.

Runnable scripts находятся в [examples](examples/README.md).
