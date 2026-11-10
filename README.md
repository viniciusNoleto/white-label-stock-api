# White Label Stock API

REST API of [White Label Stock](../white-label-stock-web), a stock system for small producers that make and sell what they stock: a bakery (perishable, made from a recipe, sold by the unit or by weight) or a furniture shop (one code per piece). It covers products with recipes, physical items with expiration and first-expiring-first-out (FEFO) picking, delivery packages that reserve stock, an audit log of every stock movement, and users with admin-approved registration. Built with **Laravel 13** (PHP 8.5) and **PostgreSQL 16**.

[![CI](../../actions/workflows/ci.yml/badge.svg)](../../actions/workflows/ci.yml)

## What "white label" means here

**One deployment per brand.** Each customer gets their own instance: their own database, domain, business timezone (`BUSINESS_TIMEZONE`), and their own branding in the web app. The attribution footer stays (see [License](#license)). There is **no multi-tenancy inside one database**, and that is deliberate:

- the target customer is a single small business with a handful of users who all work on the same stock;
- isolating by instance removes a whole class of bugs (one brand reading another brand's stock) and makes backup, restore and deletion per customer trivial;
- going multi-tenant later is a known change: a `tenant_id` on every table, a global scope, tenant-aware unique indexes and lock keys. It is not hidden work.

## The web app and why it is Angular

The web app (Angular SSR) is a separate project. Its Express server proxies `/api/**` to this API, forwarding the `Authorization` header and the client IP (`X-Forwarded-For`), so the browser only talks to the web origin (no CORS). Any other client (Postman, curl, a future mobile app) calls the API directly with `Authorization: Bearer <access_token>`. The contract is plain JSON, so the front end is replaceable: Angular was chosen for the web app because I was studying the three big front-end technologies (React, Vue and Angular) and wanted to build one system with each of them, so this one is in Angular. Nothing in the API depends on it.

## Stack

- [Laravel 13](https://laravel.com): Controller → FormRequest → DTO ([spatie/laravel-data](https://spatie.be/docs/laravel-data)) → Action → Eloquent, API Resources. Conventions are in [`AGENTS.md`](AGENTS.md); the architecture tests check the ones that can be checked mechanically. The locking rules are explained for humans in [`docs/concurrency.md`](docs/concurrency.md).
- PostgreSQL 16: row locks, `CHECK` constraints and transactional DDL are part of the design.
- [Pest 5](https://pestphp.com): feature tests against a real Postgres, plus real multi-process concurrency tests.
- Docker: nginx + php-fpm + scheduler + Postgres.

## Quality gates

Every push and pull request runs [CI](.github/workflows/ci.yml); `make ci` runs the same steps locally:

| Step | Tool | Command |
|---|---|---|
| Style | Pint ([`pint.json`](pint.json): Laravel preset adapted to the project style) | `make lint` |
| Static analysis | Larastan level 5 ([`phpstan.neon`](phpstan.neon)), no baseline | `make analyse` |
| Vulnerable dependencies | `composer audit` | `make audit` |
| Tests | Pest: `Unit` (helpers, response mapping, transaction retries, architecture rules), `Feature` (every endpoint, error paths with `failTable()`, end-to-end bakery and furniture-shop scenarios, **stock ledger reconciliation after every test**) and `Concurrency` (forked processes racing on the same stock and the same refresh token) | `make test` |
| Coverage | pcov, line coverage of `app/`, **minimum 95%** (currently ~97%) | `make test-coverage` |

The tests run in CI **inside the production PHP image**, built from the same [`Dockerfile`](.docker/php/Dockerfile) the server builds (plus pcov, only there). A Dependabot PR that bumps the PHP base image runs the whole suite on the new version before it can reach `make deploy`. The nginx image is built too. Style, static analysis and the audit do not depend on the PHP patch version and run on `setup-php`, which is faster.

How the audit trail is protected, and how far each layer goes:

- **Static (heuristic).** The architecture test reads the Actions and fails if one that writes to `items` does not call the movement recorder, or if anything writes to `items` through `DB::table()`. It catches the usual mistake (a new endpoint that forgets the log). It cannot prove that every code path inside an Action records.
- **Dynamic (behavioral).** After every feature test, `stockLedgerViolations()` compares each item with its movements: the last movement must leave the item's current quantity and storage, and the signed sum must equal the quantity for items born with a movement. A write that skips the log, wherever it comes from, fails the test that exercised it. A dedicated test proves the check catches an `Item::query()->update()` done behind the Actions' back.

The CI jobs have time limits, and the concurrency harness kills a stuck child process after 30 s, so a lock bug fails the build instead of hanging it.

## Response format

Every response uses the same envelope. `message` is always translated into the web app's three locales:

```json
{ "success": true, "message": { "pt-br": "…", "es-mx": "…", "en-us": "…" }, "data": {} }
```

Errors always carry `data: null`:

| Status | When |
|---|---|
| 400 | malformed JSON |
| 401 | no session or invalid credentials |
| 403 | admin-only route, or signing in while the registration is still pending |
| 404 | not found (including invalid ids) |
| 409 | record in use, or a concurrent change that survived the retries ("nothing was saved, try again") |
| 422 | invalid payload or business rule (insufficient stock, expired item, delivered package…) |
| 429 | rate limit on login, registration or refresh (`Retry-After` header) |
| 500 | unexpected error |
| 503 | maintenance during a deploy |

Every response carries an `X-Request-Id` header (an incoming one is reused), and every log line of that request includes the same id.

## Endpoints

| Method | Path | |
|---|---|---|
| `POST` | `/api/auth/login` | `{ email, password }` → session (see below). Rate-limited |
| `POST` | `/api/auth/register` | public `{ name, email, password }` → creates a **registration request** (409 if the email is taken); an admin approves or rejects it. Rate-limited |
| `POST` | `/api/auth/logout` | revokes the Bearer access token and the `refresh_token` sent in the body |
| `GET` | `/api/auth/me` | `{ user }` of the Bearer access token (401 without a valid one) |
| `PUT` | `/api/auth/password` | `{ current_password, password }` → signs out every session of the user and returns a new one (same body as login). Wrong current password → 422. Rate-limited |
| `POST` | `/api/auth/token/refresh` | `{ refresh_token }` → new session (rotates the refresh token). Rate-limited |
| `GET` `POST` | `/api/units` | `?page=&per_page=` → `{ items, meta }` by name / create |
| `PUT` `DELETE` | `/api/units/{id}` | update / delete (409 when in use) |
| `GET` `POST` | `/api/item-categories` | `?page=&per_page=` → `{ items, meta }` by name / create (`color_hex` = `#RRGGBB`) |
| `PUT` `DELETE` | `/api/item-categories/{id}` | update / delete |
| `GET` `POST` | `/api/storages` | `?page=&per_page=` → `{ items, meta }` by name / create |
| `PUT` `DELETE` | `/api/storages/{id}` | update / delete |
| `GET` `POST` | `/api/products` | `?search=&page=&per_page=` → `{ items, meta }` with `stock_quantity`, `reserved_quantity`, `expired_quantity`, `items_count` / create `{ name, sku?, unit_id, is_atomic, shelf_life_days?, category_ids, components: [{ id, quantity_required }] }` |
| `PUT` `DELETE` | `/api/products/{id}` | replace everything (recipe cycles → 422; atomic ↔ bulk only without items → 422) / delete (409 with items in stock, in a recipe or in packages; empty bulk lots are deleted with the product, their history stays in the movements) |
| `GET` | `/api/items` | `?search=` (item code only) `&product_id=&storage_id=&expiration=expired\|expiring\|valid&expires_at=YYYY-MM-DD&page=&per_page=` → items by product, first-expiring first, with `reserved_quantity` |
| `POST` | `/api/items` | `{ product_id, quantity, produced_at?, storage_id?, consume_components? }` → atomic: `quantity` items with their own codes (always generated: `{SKU}-0001`…, batch up to 500); bulk: one lot. `expires_at` = `produced_at` + shelf life; `produced_at` defaults to today in the business timezone and cannot be in the future (422). `consume_components` takes the recipe out of the stock (FEFO, never expired items) |
| `PATCH` | `/api/items/{id}` | `produced_at` (recalculates `expires_at`; not in the future) / `expires_at` (never before the production date, counting what is already stored: 422) / `storage_id` (`null` unlinks) / bulk only: `quantity` \| `quantity_add` \| `quantity_subtract` (never below 0, below what is reserved, or above the column limit: 422) |
| `DELETE` | `/api/items/{id}` | write-off, with a body `{ reason: expired\|damaged\|lost\|other }` (409 when reserved in a pending package). `reason` is optional only because the current web client does not send it yet; without it the write-off is not counted in the loss reports, so clients should always send it |
| `GET` | `/api/stock` | `?search=` (product name or SKU) `&storage_id=&expires_at=YYYY-MM-DD&page=&per_page=` (filters apply to items before grouping) → operation view: atomic items grouped per product (`kind: group`), bulk lots one per row (`kind: record`, empty lots left out) |
| `GET` | `/api/stock-movements` | `?search=` (item code or product name) `&type=entry\|production\|consumption\|adjustment\|transfer\|delivery\|write_off&reason=expired\|damaged\|lost\|other&product_id=&storage_id=&date_from=&date_to=` (calendar days in the business timezone) `&page=&per_page=` → audit log, newest first. Example: losses to expiration this month = `?type=write_off&reason=expired&date_from=2026-11-01&date_to=2026-11-30` |
| `GET` | `/api/stock-movements/summary` | same filters (no pagination) → `{ items: [{ product_id, product_name, unit_abbreviation, type, reason, quantity, movements_count }] }`: the signed **total** per product, type and reason. "How many kg did I lose to expiration in November?" is one row per product instead of summing pages. Grouped by the names copied into the movements, so deleted or renamed products still show as they were; write-offs without a reason show as `reason: null`, which measures how much of the loss is still unclassified |
| `GET` `POST` | `/api/delivery-packages` | `?status=pending\|delivered&page=&per_page=` → `{ items, meta }` / create `{ items: [{ product_id, quantity, item_ids? }], recipient?, notes? }` → reserves items first-expiring first, or exactly the chosen `item_ids` (atomic only; `quantity` is optional with them and, when sent, must equal their count), never expired and only free stock (422 otherwise), and returns them as `allocations` (codes to pick). `created_by` records who assembled it |
| `GET` `PUT` `DELETE` | `/api/delivery-packages/{id}` | show / replace `items` + `recipient` + `notes` (pending only) / delete a **pending** package (frees the reservation; a delivered one → 422) |
| `POST` | `/api/delivery-packages/{id}/deliver` | marks as delivered: atomic items leave the stock, bulk lots are subtracted (422 if already delivered, short, or an item **expired since it was reserved**) |
| `GET` | `/api/registration-requests` | **admin** · `?search=&page=&per_page=` → pending requests, oldest first |
| `POST` | `/api/registration-requests/{id}/approve` | **admin** · creates a regular user with the requested password and deletes the request |
| `DELETE` | `/api/registration-requests/{id}` | **admin** · rejects (deletes) the request |
| `GET` | `/api/users` | **admin** · `?search=&page=&per_page=` → admins first, then by name |
| `DELETE` | `/api/users/{id}` | **admin** · deletes a regular user and their sessions (admins → 422) |
| `PUT` | `/api/users/{id}/password` | **admin** · `{ password }` → sets a new password for a regular user who forgot theirs and signs them out (admins → 422: an admin never takes over another admin's account) |
| `GET` | `/up` | health check: 200 only if the app **and the database** answer |

Everything except the public `auth` routes and `/up` requires `Authorization: Bearer <access_token>`. Unknown routes answer 404 with or without a token. Text searches treat `%` and `_` literally. Quantities are `decimal(15,4)`: they are sent as JSON numbers or strings and validated to at most 4 decimal places and 11 integer digits, then handled as strings with `bcmath`, never as floats. They are returned as strings (`"2.5000"`).

## Stock rules and the guarantees behind them

| Rule | How it is guaranteed | Proven by |
|---|---|---|
| Nothing expired leaves the stock: not into a recipe, a package, or a **delivery** | The expiration date is checked when items are reserved and checked again when the package is delivered, under a row lock | `DeliveryPackageTest` (*refuses an item that expired after being reserved*) |
| "Today" is the shop's day, not UTC | `business_today()` uses `BUSINESS_TIMEZONE`; timestamps stay in UTC | Timezone tests in `ItemTest`, `DeliveryPackageTest`, `StockMovementTest` |
| The same unit is never sold twice; a lot never goes negative | Usable items are locked (`FOR UPDATE`) before computing free stock; `CHECK (quantity >= 0)` in the database. Why reading the reservations without a lock is still correct: [`docs/concurrency.md`](docs/concurrency.md#1-the-problem-two-people-one-cake) | `tests/Concurrency/StockConcurrencyTest.php` (12 processes racing for 5 units) |
| No deadlock between reservation, delivery and production | Two rules. **Items** are always locked in one order (`Item::lockedInStockOrder`: product id, then item id) by every path that locks more than one. **Product rows** are locked with `FOR NO KEY UPDATE` (`Product::lockedForWrite`), never `FOR UPDATE`: every insert with a foreign key to `products` takes `FOR KEY SHARE` on that row, and only `FOR UPDATE` conflicts with it. Transactions still retry on deadlock and serialization failures, recognized by SQLSTATE and not by the (translatable) message text (`DB_TRANSACTION_ATTEMPTS`), and anything left answers 409, not 500. Full explanation: [`docs/concurrency.md`](docs/concurrency.md) | `tests/Concurrency/StockConcurrencyTest.php` runs the races **with retries turned off**, so a deadlock shows up as a failed process. Both tests failed every run against the code before the fix (per-item-id order; `FOR UPDATE` on the product while producing P from C and delivering C + P) |
| A stuck lock does not take the API down | `lock_timeout` (5 s → 409 at once, **not retried**: it already waited) and `statement_timeout` (30 s → 500) are sent on every Postgres connection (`DB_LOCK_TIMEOUT`, `DB_STATEMENT_TIMEOUT`) | `DatabaseTimeoutsTest`, `TransactionRetryTest` |
| Item codes never collide, even between products that share a prefix | The product row serializes one product's sequence; an advisory lock on the code prefix (the last lock of the transaction) serializes two products whose prefixes match (`P5` without SKU × SKU `P5`) | `StockConcurrencyTest` › *never collides item codes…* |
| Dates make sense | `produced_at` is never in the future (business day) and `expires_at` is never before `produced_at`, checked against the stored values on a partial update | `ItemTest` |
| Recipes have no cycles | Breadth-first search, serialized with a Postgres advisory lock so two concurrent edits cannot each create half of a cycle | `ProductTest` |
| Every stock change is in the audit log | `RecordStockMovementsAction` in the same transaction, before the item is deleted | `StockMovementTest`, the ledger reconciliation after every feature test, and `ArchitectureTest` (static, see [Quality gates](#quality-gates)) |
| "How much did I lose to expiration?" has an answer | Write-offs carry a `reason`; the movement log filters by type, reason and business day | `BakeryTest` writes off the expired cakes with `reason: expired` and reads the month's losses back |
| A delivered package is the record of the sale | It cannot be edited or deleted; its movements keep the package id | `DeliveryPackageTest` |

## Authentication

Login and refresh return the session in the body:

```json
{ "user": { "id": 1, "name": "…", "email": "…", "is_admin": true }, "access_token": "…", "refresh_token": "…", "expires_in": 900, "refresh_expires_in": 2592000 }
```

- **Opaque tokens** (32 random bytes, not JWT); only their SHA-256 hash is stored. Access tokens last 15 minutes, refresh tokens 30 days. Expired tokens are deleted daily by `auth:prune-tokens` (scheduler).
- **Rotation with reuse detection.** Every refresh rotates the token inside its family. Presenting an already rotated token revokes the whole family, **including the access tokens issued to it** (they carry the `family_id`), so access ends immediately instead of within 15 minutes, and logs `auth.refresh_token_reused`. Other devices (other families) keep working.
- **Concurrent refreshes** with the same token within 10 s (several tabs, SSR + browser) get the same new pair. The lock and the encrypted result live in the shared cache. This is proven with parallel processes on the production cache store (`tests/Concurrency/RefreshSessionConcurrencyTest.php`). A refresh that cannot get that lock within the window answers 409 ("try again"), not 500.
- **All or nothing.** The rotation and the new access token are written in one transaction: if issuing the access token fails, the rotation is undone and the same refresh token keeps working, instead of the client's retry being mistaken for token theft and the user being logged out.

  *Trade-off, accepted on purpose:* during those 10 s, whoever presents the same refresh token gets the same pair, so a thief who replays a stolen token inside the window is not detected as reuse. Without the window, the SSR and the browser refreshing at the same moment would trigger reuse detection and log real users out several times a day. The window is short, the refresh token travels only between the web server and the API, and after the window any replay revokes the family.
- **Passwords.** A user changes their own password with the current one (`PUT /api/auth/password`, rate-limited); every session ends and the caller gets a new one. An admin resets a regular user's forgotten password (`PUT /api/users/{id}/password`). An admin who forgot their own password is recovered by the server operator: `php artisan user:reset-password <email>`. Every path signs the user out everywhere.
- **Rate limits:**
  - login: 5/min per e-mail + IP and 20/min per IP;
  - registration: 3/min and 10/hour per IP;
  - refresh: 10/min per token, 300/min per IP. It is keyed by token because the web SSR refreshes from a single server IP.

  The client IP comes from `X-Forwarded-For` only when the request comes from a private network (`TRUSTED_PROXIES`), so a client cannot fake its way around the limit.
- **E-mail enumeration.** Login spends exactly one bcrypt check on every path (a throwaway hash when the e-mail does not exist), so response time does not reveal which accounts exist. Registration still answers 409 for a taken e-mail: with no transactional e-mail to send "check your inbox", a generic answer would leave a real user waiting forever for an approval that cannot come. The rate limit bounds how fast anyone can probe.

### Permissions (a decision, not an omission)

There are two roles. **Admins** manage access: they approve or reject registrations, reset passwords, and list or delete users. **Regular users** run the stock, and any approved user can do every stock operation, including destructive ones (delete a product, write off an item, delete a pending package someone else assembled).

Why: the customer is one small shop where the few approved users are the owner and their employees, who already share the counter, the oven and the stock room. Splitting stock permissions per person would add friction to every sale without protecting anything the shop cares about. What protects the shop is that destructive operations are **bounded and traceable**:

- the database refuses to delete anything in use (409), and empty bulk lots are the only thing removed with a product;
- reserved items cannot be written off, and delivered packages cannot be edited or deleted;
- every stock change is in the movement log with its author, copied so it survives the user's deletion, and every package records who assembled it;
- access is approved by an admin, and revoked at once when the user is deleted or their password is reset.

When a customer needs finer control, the change is known: a `role` column next to `is_admin`, one middleware alias per role (the `admin` middleware is the model), and the route groups in `routes/api.php` decide which role reaches which operation. No Action changes.

## Local setup

Requirements: Docker + Docker Compose (PHP and Composer run inside the containers).

```bash
cp .env.example .env   # fill SEED_ADMIN_NAME / SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD
make install
```

`make install` builds the images and starts the four containers: `white-label-stock-api-nginx`, `-php`, `-scheduler` and `-postgres`. Then it runs `composer install`, generates the key, migrates and seeds the admin user. The API answers at `http://localhost:$NGINX_PORT` (default `8005`). Postgres is published on `$FORWARD_DB_PORT` (default `5443`, also used by the web e2e setup). The `${DB_DATABASE}_testing` database, which the tests always use, is created on the first start of the Postgres volume.

| Command | Effect |
|---|---|
| `make up` / `make down` / `make restart` | Start / stop / restart the containers |
| `make shell` / `make in` / `make tinker` | Shell in the PHP container / pick any container / Laravel REPL |
| `make migrate` / `make migrate-fresh` / `make db-seed` | Migrations / rebuild the database / seed |
| `make test` | Pest suite (`make test TEST="--filter=units"`, `TEST="--testsuite=Concurrency"`) |
| `make test-coverage` | Pest with line coverage, failing below 95% (the local image includes pcov; production does not) |
| `make lint` / `make lint-fix` / `make analyse` / `make audit` / `make ci` | Quality gates (see above) |
| `make backup` / `make restore file=…` | `pg_dump` to `backups/` / restore one (asks for confirmation and dumps the current state first) |
| `make recreate-testing-database` | Drop and recreate `${DB_DATABASE}_testing` |
| `make logs` / `make logs-docker` | Follow `storage/logs/laravel.log` / container output |

The `Makefile` is only the command index: backup, restore and deploy live in [`scripts/`](scripts) (plain bash, `set -euo pipefail`, checked with shellcheck), so each step can be read and run on its own.

## Deploy

The server runs **Nginx Proxy Manager** (NPM), which routes each domain to its app and handles HTTPS. `docker-compose.production.yml` publishes no port: the API's nginx joins NPM's Docker network, where the web container reaches it.

**First install**

1. `.env`:
   - `APP_ENV=production` and `APP_DEBUG=false`;
   - `DOCKER_COMPOSE_FILE=docker-compose.production.yml`;
   - a strong `DB_PASSWORD`;
   - `BUSINESS_TIMEZONE` of the customer;
   - `LOG_STACK=stderr` and `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` (one JSON line per log, with `request_id` and `user_id`);
   - `LOG_SLACK_WEBHOOK_URL` (Slack-compatible webhook: Slack, Mattermost, Discord with `/slack`): every 500 becomes an alert;
   - a git remote the server can **push** to (deploy key with write access): the deploy pushes its release tags;
   - `PROXY_NETWORK` = NPM's network (`docker network ls`).
2. `make install-production`. This builds, starts, installs without dev dependencies, generates the key only if there is none, migrates, seeds and caches.
3. In the web's `.env`: `API_URL=http://white-label-stock-api-nginx`.

**Updates.** Run `make deploy` (it pulls `DEPLOY_BRANCH`, default `main`) or `make deploy REF=<tag or commit>`. It refuses to run with uncommitted changes on the server, then, in order:

1. maintenance mode first: the old code answers 503 in the envelope, so nothing is written from here on;
2. `pg_dump` to `backups/` (consistent, since nothing is writing), keeping the last `BACKUP_KEEP` dumps and copying them to `BACKUP_OFFSITE` with `rsync` when it is set;
3. the code: `git pull --ff-only`, or `git checkout REF`;
4. rebuild and recreate only the changed containers (the database keeps running);
5. `composer install --no-dev`;
6. migrations;
7. caches;
8. **restart `php-fpm` and the scheduler.** Production runs with `opcache.validate_timestamps=0`, and the code comes from a bind mount, so without a restart php-fpm would keep serving the old bytecode;
9. back online;
10. tag the release (`deploy-YYYYMMDD-HHMMSS`) and **push the tag** to `DEPLOY_REMOTE` (default `origin`). Every version that went live has a tag, which is what rollback uses, and it lives in the remote, not only on the server's disk. If the push fails, the deploy is not undone (the API is already up); it prints the command to push it by hand.

If a migration fails, the API stays in maintenance and nothing half-applied remains, because Postgres DDL is transactional.

**Rollback**

1. List what went live: `git fetch --tags && git tag -l 'deploy-*'`. On a new server, clone, `make install-production`, restore the latest dump and deploy the tag: the tags come from the remote.
2. Code only (the usual case: migrations are additive, see below): `make deploy REF=<previous deploy tag>`.
3. Code and data, if a release wrote data the old code cannot read: `make restore file=backups/<the dump taken by the failed deploy>`, then `make deploy REF=<previous deploy tag>`. `make restore` asks for the database name, enters maintenance and **dumps the current state first**: restoring the wrong file is undone with `make restore file=<that safety dump>`.
4. To follow the branch again afterwards: `make deploy` (it checks out `DEPLOY_BRANCH`).

**Backups.** `make backup` runs before every deploy; schedule it daily on the host as well (cron: `cd /path && make backup`). Set `BACKUP_OFFSITE` (another disk or `user@host:/path/`): a dump on the same disk as the database does not survive the disk. `make backup-verify` restores the latest dump into a throwaway database and counts the rows, which proves the backup can actually be restored; run it after setting up and then periodically.

**Migration policy (expand/contract).** A migration only adds: new nullable columns, new tables, constraints and indexes. Old code keeps working on the new schema, which is what makes the code-only rollback above safe. A column or table is removed only in a later release, after its data has been migrated and no deployed code reads it. One early migration (`2026_11_09_000001`) predates this policy: it dropped the prototype `inventory_items` tables before there was any production data. It is kept as is, because rewriting a migration that has already run is worse than documenting it.

**Operations**

- Logs: `make logs-docker`. Each line carries the request's `request_id` and, on authenticated routes, the `user_id`; with the JSON formatter they are fields, ready for `jq` or a log collector. Use `LOG_LEVEL=info` (or `warning`) in production. The production compose rotates container logs (5 × 10 MB per container), so they cannot fill the disk.
- Errors: with `LOG_SLACK_WEBHOOK_URL` set, the `slack` channel joins the log stack by itself and every log from `LOG_SLACK_LEVEL` (default `error`, i.e. the 500s) is posted with its request id and user. Business-rule errors (4xx) and handled races (409) are not reported. The message the user sees carries the same `X-Request-Id`, so a support call leads straight to the log line. A full error tracker (Sentry: grouping, release tracking) is the next step when there is more than one customer.
- Database timeouts: `DB_LOCK_TIMEOUT` (default `5s`) and `DB_STATEMENT_TIMEOUT` (default `30s`).
- Versions are pinned: Docker base images by exact tag, GitHub Actions by commit SHA, PHP packages by `composer.lock`. Dependabot opens weekly PRs to update them through CI.
- Health: Docker healthchecks call `/up` (nginx) and the FastCGI port (php-fpm). Point an external uptime monitor (UptimeRobot, Better Stack) at `/up`. The API publishes no port, so expose only that path through NPM (a location forwarding `/up` to `white-label-stock-api-nginx`). It is the only alert that still fires when the whole server is down, which the webhook above cannot do.
- Scheduled jobs: `routes/console.php`, run by the `scheduler` container.

## Try it

With the containers up (`make install`) and the admin from `SEED_ADMIN_*` (needs `curl` and `jq`):

```bash
API=http://localhost:8005/api
TOKEN=$(curl -s $API/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"…"}' | jq -r .data.access_token)
H=(-H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json')

UNIT=$(curl -s "${H[@]}" $API/units -d '{"name":"Unidade","abbreviation":"un"}' | jq .data.id)
CAKE=$(curl -s "${H[@]}" $API/products -d "{\"name\":\"Bolo\",\"sku\":\"BOLO\",\"unit_id\":$UNIT,\"is_atomic\":true,\"shelf_life_days\":3,\"category_ids\":[],\"components\":[]}" | jq .data.id)
curl -s "${H[@]}" $API/items -d "{\"product_id\":$CAKE,\"quantity\":3}" | jq '.data.items[].code'   # "BOLO-0001"…
curl -s "${H[@]}" $API/delivery-packages -d "{\"items\":[{\"product_id\":$CAKE,\"quantity\":2}],\"recipient\":\"Ana\"}" | jq '.data.items[0].allocations'
curl -s "${H[@]}" "$API/stock-movements" | jq '.data.items[] | {type, item_code, quantity, balance}'
```

The two end-to-end scenarios in `tests/Feature/Scenarios/` (bakery and furniture shop) are the longer, executable version of this walkthrough.

## Known limitations

These are deliberate decisions, listed so they are not mistaken for oversights:

- **Single tenant per instance.** See [What "white label" means here](#what-white-label-means-here).
- **Two roles only**, no per-action policies. See [Permissions](#permissions-a-decision-not-an-omission).
- **No prices.** The system answers "what do I have, what expires, what left and why", not "how much did I sell". Unit price on the product, copied to the package line and to the movement, is the next product step (it fits the current structure: one column and two snapshots).
- **No alerts.** "Expiring" and "expired" are filters the user has to open; a daily summary or minimum-stock alert is not built.
- **Empty bulk lots stay** in `items` (with quantity 0) until their product is deleted. They are hidden from the stock view.
- **Production runs from a bind mount, not a versioned image.** Rollback is by deploy tag plus a database dump. Building a tagged image in CI and deploying by tag is the next step for the pipeline.
- **No transactional e-mail.** Hence the 409 on registration and password recovery through an admin (or the server operator for admins) instead of a "forgot password" e-mail.
- **Alerts by webhook, not an error tracker.** 500s reach a chat channel with the request id; grouping, release tracking and rate limiting of alerts (Sentry) are not wired in.
- **The web client does not send the write-off `reason` yet**, so it is optional in the contract. The summary endpoint shows how much of the loss is unclassified (`reason: null`); making it required is a coordinated change with the web.
- **No returns.** A delivered package is immutable and there is no "return" movement type: a returned item is registered as a new entry today. A proper return (linked to the package line, its own movement type, excluded from sales figures) needs a product decision first.
- **Personal data**: users' names are copied into the audit log, and packages keep a free-text `recipient`, so history stays readable after a user is deleted. A deletion request under LGPD would anonymize those copies; there is no automated flow or retention policy for it yet.

## Contributing

`main` is protected: changes arrive through a branch and a pull request that passes CI. One concern per PR (a fix, a feature, an infra change), small commits whose message says **why** (`fix(items): reject a production date in the future`), and the PR template asks what changed, why, how it was tested, and whether the JSON contract consumed by the web changed. A rule about locks or stock comes with a test in `tests/Concurrency` that fails without it.

## License

The same terms as the web app ([LICENSE.md](LICENSE.md)): free to use, modify, white-label and sell, keeping the attribution footer on every screen of the web app or any front end built on this API.
