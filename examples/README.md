# Examples

`valid-request.php` draws one data-only case, materializes it using Nyholm's
PSR-17 factory, and validates the result with `openapi-contract`.

`negative-request.php` walks the constructive negative categories on one
operation and shows that every misuse case is rejected by contract validation
before any transport would run.

`document-examples.php` shows the document's `example`/`examples` running as
the deterministic example phase of `OperationProperty`: a point fault the
document describes is found by name on the first run, before any random trial.

`response-cases.php` generates a contract-valid provider response and a
provably invalid one (`enum` misuse) for the same operation and shows the
core validator accepting the first and rejecting the second — the harness for
testing an API client without live traffic.

`suite-check.php` runs `ContractSuite` against an in-process PSR-15 handler:
valid trials must conform without a 5xx, and a constructive negative case must
be rejected without a 5xx. Its `afterRequest` hook also counts one state reset
after every in-process request.

`coverage-report.php` attaches an `OperationCoverage` record to a suite with
two selected operations, exercises only one of them, prints the JSON coverage
report, and shows the opt-in `assertComplete()` gate naming the operation that
never ran a trial.
