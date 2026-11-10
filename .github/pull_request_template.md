## What changes

<!-- One concern per PR: a fix, a feature or an infra change. -->

## Why

<!-- The problem or the decision behind it. Link the issue, if there is one. -->

## How it was tested

- [ ] `make ci` passes (lint, Larastan, audit, Pest)
- [ ] New rule or endpoint has a test that **fails without the change**
- [ ] Locks or stock under concurrency: test in `tests/Concurrency`, inside `withoutTransactionRetries()`

## Contract and operations

- [ ] JSON contract consumed by the web unchanged (or aligned with `white-label-stock-web`)
- [ ] New messages in the three `lang/*/messages.php`
- [ ] Migration only expands (new nullable column, table, index, `CHECK`)
- [ ] Stock change records a movement in the same transaction
- [ ] README / `AGENTS.md` / `docs/concurrency.md` updated if behavior or a convention changed
