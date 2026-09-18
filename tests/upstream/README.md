# XBoard compatibility fixtures

These unmodified reference files come from:

- Repository: https://github.com/cedar2025/Xboard
- Commit: `4f48e61a2cbc6db5338872b6bdb45ef954ec1256`
- License: MIT; retained in `LICENSE`.

The tests execute the original plugin manager, configuration service, registration
service, user service, middleware, observers, traffic reset service, and relevant
models. The controller/request samples document the original dependency-injection
contract. Peripheral model and service substitutes are clearly labeled in
`../HarnessModels.php`; they are not distributed in the installable plugin ZIP.

Do not format or modify upstream samples. Refresh them as a deliberate compatibility
update with a new pinned commit and rerun the integration tests.
