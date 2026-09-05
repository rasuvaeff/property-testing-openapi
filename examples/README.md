# Examples

| Script | Shows | Needs server? |
|---|---|---|
| `valid-request.php` | Drawing one data-only case, materializing it with Nyholm's PSR-17 factory, and validating the result with `openapi-contract` | No |
| `negative-request.php` | The constructive negative categories on a JSON operation and the multipart part-media-type category on a second one, every misuse case rejected by contract validation before any transport runs | No |
| `document-examples.php` | The document's `example`/`examples` running as the deterministic example phase of `OperationProperty` — a point fault the document describes, found by name before any random trial | No |
| `response-cases.php` | A contract-valid provider response and a provably invalid one (`enum` misuse) for the same operation, accepted and rejected by the core validator — the harness for testing an API client without live traffic | No |
| `suite-check.php` | `ContractSuite` against an in-process PSR-15 handler: valid trials conform without a 5xx, a constructive negative case is rejected without a 5xx, and the `afterRequest` hook counts one state reset per request | No |
| `coverage-report.php` | `OperationCoverage` on a suite with two selected operations where only one runs, the JSON coverage report, and the opt-in `assertComplete()` gate naming the operation that never ran a trial | No |

No script needs a server: the only transport any of them uses is in-process.

Run from the package root after `make install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/valid-request.php
```
