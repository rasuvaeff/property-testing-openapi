# Examples

`valid-request.php` draws one data-only case, materializes it using Nyholm's
PSR-17 factory, and validates the result with `openapi-contract`.

`negative-request.php` walks the constructive negative categories on one
operation and shows that every misuse case is rejected by contract validation
before any transport would run.
