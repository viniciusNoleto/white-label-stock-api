# Concurrency: how the stock stays correct under parallel requests

This is the human explanation of the locking rules. [`AGENTS.md`](../AGENTS.md) has the short version for whoever writes code; the proofs are the tests in [`tests/Concurrency`](../tests/Concurrency), which run real forked processes against Postgres **with transaction retries turned off**, so a deadlock shows up as a failed process instead of being hidden by a retry.

Everything here runs on Postgres' default isolation level, **READ COMMITTED**: every statement sees the data committed before *that statement* started, not before the transaction started.

## 1. The problem: two people, one cake

Two employees build a delivery package at the same moment, and there is one cake left. Without locks, both transactions read "1 free", both reserve it, and the shop sells the same cake twice.

The fix is to **lock the rows you are about to decide on, then read what is reserved**:

```
AllocateProductStockAction
  1. SELECT usable items of the product ... FOR UPDATE   (Item::usable()->lockedInStockOrder())
  2. SELECT SUM(reserved) for those items                (Item::reservedLinesQuery(), no lock)
  3. free = quantity - reserved, first-expiring first
  4. INSERT the package lines
```

Why step 2 can run without a lock and still be right:

- every path that reserves an item (FEFO reservation, chosen items, delivery) locks that item first (step 1);
- so, once transaction B gets the lock that A was holding, A has already committed or rolled back;
- step 2 is a **new statement**, so under READ COMMITTED it takes a new snapshot and sees A's committed reservation lines.

And if A changed the item while B was waiting (a delivery set a lot to zero, or deleted an atomic item), Postgres re-checks B's `WHERE` against the new version of the row when the lock is released (the "EvalPlanQual" recheck): a row that no longer matches `quantity > 0` simply drops out of B's result. B never decides on a stale row.

Proof: `StockConcurrencyTest` › *never reserves the same unit twice* (12 processes, 5 cakes: exactly 5 packages succeed, 7 get "insufficient stock", no item appears twice).

## 2. Deadlocks, and the two rules that prevent them

A deadlock is a cycle: A holds X and waits for Y, B holds Y and waits for X. Postgres detects it after `deadlock_timeout` and kills one of them (SQLSTATE `40P01`). Retrying hides most of them, but a cycle that is *structural* (two code paths that always lock in opposite orders) turns into random 409s under load. The rules below remove the cycles instead of absorbing them.

### Rule 1: items are always locked in one global order

`Item::lockedInStockOrder()` = `ORDER BY product_id, id FOR UPDATE`. Every path that locks more than one item uses it, and every path that walks several products walks them by product id (`SyncDeliveryPackageItemsAction`, `CreateItemsAction::consumeRecipe`).

The bug it fixed: delivery locked "by item id" while reservation locked "by product". If product A has a smaller id but product B's items have smaller ids, one path grabbed B's items first and the other A's items first. Reproduced in *does not deadlock when deliveries and reservations lock the same items from opposite sides*.

### Rule 2: product rows are locked with `FOR NO KEY UPDATE`, never `FOR UPDATE`

Postgres has four row-lock strengths. The two that matter here:

| Held \ Requested | `FOR KEY SHARE` | `FOR NO KEY UPDATE` | `FOR UPDATE` |
|---|---|---|---|
| `FOR KEY SHARE` | ok | ok | **waits** |
| `FOR NO KEY UPDATE` | ok | **waits** | **waits** |
| `FOR UPDATE` | **waits** | **waits** | **waits** |

Every `INSERT` into a table with a foreign key to `products` (items, stock movements, package lines) takes `FOR KEY SHARE` on the referenced product row. That is how Postgres guarantees the product is not deleted, or its key changed, while the child row is being inserted.

Production of P from component C needs to serialize P's item-code sequence, so it locks P's row. The cycle with `FOR UPDATE`:

1. **Production of P** locks P's row (`FOR UPDATE`), then wants C's items.
2. **Delivery of a package with C and P** locks C's and P's items (rule 1), then inserts the stock movement for P, which needs `FOR KEY SHARE` on P's row.
3. 1 waits for 2 (C's items), 2 waits for 1 (P's row): deadlock.

With `FOR NO KEY UPDATE` (`Product::lockedForWrite()`), step 2 does not wait at all, because `FOR KEY SHARE` and `FOR NO KEY UPDATE` are compatible. Two writers on the same product are still serialized, because `FOR NO KEY UPDATE` conflicts with itself. Reproduced in *does not deadlock when production with a recipe races deliveries*. The architecture test also fails if anyone writes `Product::query()->…->lockForUpdate()`.

**What about changing the SKU?** `sku` has a unique index, so an `UPDATE` that changes it is a key update and takes the strong lock on that row: for that instant it waits for, and blocks, inserts that reference the product. It does not create a cycle, because `UpdateProductAction` never locks items: it only waits, nobody waits for something it holds while it waits. If the SKU does not change, Eloquent does not write the column and the lock stays `NO KEY UPDATE`.

### Rule 3 (new): the advisory lock on the item-code prefix is the last lock of the transaction

The product row lock serializes the code sequence of **one** product. But two products can share a prefix: a product without SKU uses `P{id}`, and another product can have exactly that SKU. Both used to read the same free codes at the same time, and one user got a 422 "code already taken" for no fault of their own.

`CreateItemsAction::nextCodes()` now takes `pg_advisory_xact_lock(1, hashtext(prefix))` before reading the free codes. It is acquired after the product row and after the recipe's items, and nothing is locked after it, so it can only make a transaction wait, never close a cycle. It is released at commit. Reproduced in *never collides item codes between products that share a code prefix* (12 processes, 60 productions split between the two products; before the lock, several processes failed with `code_taken`).

The recipe-cycle check uses the same tool for a different reason: two concurrent edits could each add half of a cycle (A→B and B→A), and neither sees the other's uncommitted edge. `SyncProductRelationsAction` serializes recipe edits with a single-key advisory lock. Single-key and two-key advisory locks live in separate key spaces, so the two never collide.

## 3. What happens when a lock is not free

| Situation | SQLSTATE | What the API does | Why |
|---|---|---|---|
| Deadlock | `40P01` | Retries the whole transaction (`DB_TRANSACTION_ATTEMPTS`, default 3), then 409 | The loser was rolled back entirely, so retrying is safe and almost always succeeds |
| Serialization failure | `40001` | Same | Same |
| Lock not obtained within `lock_timeout` (5 s) | `55P03` | **No retry**: 409 at once | It already waited 5 s; three attempts would hold the client for 15 s on a lock that is probably still held |
| Statement over `statement_timeout` (30 s) | `57014` | 500, logged | A query that slow is a bug, not contention |
| Cache lock of the refresh dedupe not obtained in time | – | 409 | Contention, like `55P03` |

Laravel's own detector recognizes a deadlock by the **English text** of the message (`"deadlock detected"`). A Postgres configured with `lc_messages = 'pt_BR'` answers "impasse detectado", and the retry would silently stop happening. `App\Base\Database\ConcurrencyErrorDetector` replaces it and decides by SQLSTATE (`tests/Unit/TransactionRetryTest.php`).

409 always means "nothing was saved, try again": every stock change happens inside one transaction, so a failed attempt leaves no partial state.

## 4. Session refresh: the same ideas outside the stock

- **Rotation** (`RotateRefreshTokenAction`) locks the refresh-token row, so two rotations of the same token never interleave.
- **Dedupe** (`RefreshSessionAction`): the web's SSR and the browser often refresh with the same token at the same moment. The first one rotates; the others, within 10 s, get the same new pair from the cache (a cache lock serializes them). Without it, the second rotation would look like token theft and revoke the whole family.
- **One transaction** (new): the rotation and the new access token are written in the same transaction. Before, the rotation committed first; if the access-token insert failed, the old refresh token was already revoked, and the client's retry was treated as reuse: a forced logout caused by an infrastructure error.

## 5. Checklist for a new stock operation

- [ ] Runs inside `DB::transaction(…, attempts: config('database.transaction_attempts'))` (the architecture test checks it).
- [ ] Locks items only through `Item::lockedInStockOrder()`, and walks products by id.
- [ ] Locks a product row only through `Product::lockedForWrite()`.
- [ ] Any advisory lock is taken after every row lock, or is documented as safe.
- [ ] Records the stock movement in the same transaction, before deleting an item.
- [ ] Has a test in `tests/Concurrency`, inside `withoutTransactionRetries()`, that **fails without the change**.
