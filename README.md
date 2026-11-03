# White Label Stock API

REST API of the [White Label Stock](../white-label-stock-web): units, item categories, storages, inventory items (with recipes/components and builds) and Bearer token sessions. Built with **Laravel 13** (PHP 8.5) and **PostgreSQL 16**.

The web app (Angular SSR) is a separate project. Its Express server proxies `/api/**` to this API (forwarding the `Authorization` header), so the browser only ever talks to the web origin. Any other client (ApiDog, Postman, curl) can call the API directly with `Authorization: Bearer <access_token>`.

## Stack

- [Laravel 13](https://laravel.com) — Controller → FormRequest → DTO → Action → Eloquent, [spatie/laravel-data](https://spatie.be/docs/laravel-data) DTOs, FormRequests, API Resources
- PostgreSQL 16
- [Pest 5](https://pestphp.com) (feature tests against a real Postgres database)
- Docker: nginx + php-fpm + Postgres

## Response format

Every response uses the same envelope; `message` is always translated into the three locales of the web app:

```json
{ "success": true, "message": { "pt-br": "…", "es-mx": "…", "en-us": "…" }, "data": {} }
```

Errors always carry `data: null`: 400 malformed JSON · 401 no session · 404 not found (including invalid ids) · 409 record in use · 422 invalid payload or business rule (e.g. insufficient stock) · 500 unexpected.

## Endpoints

| Method | Path | |
|---|---|---|
| `POST` | `/api/auth/login` | `{ email, password }` → session (see below) |
| `POST` | `/api/auth/logout` | revokes the Bearer access token and the `refresh_token` sent in the body |
| `GET` | `/api/auth/me` | `{ user }` of the Bearer access token (401 without a valid one) |
| `POST` | `/api/auth/token/refresh` | `{ refresh_token }` → new session (rotates the refresh token) |
| `GET` `POST` | `/api/units` | list (by name) / create |
| `PUT` `DELETE` | `/api/units/{id}` | update / delete (409 when in use) |
| `GET` `POST` | `/api/item-categories` | list / create (`color_hex` = `#RRGGBB`) |
| `PUT` `DELETE` | `/api/item-categories/{id}` | update / delete |
| `GET` `POST` | `/api/storages` | list / create |
| `PUT` `DELETE` | `/api/storages/{id}` | update / delete |
| `GET` | `/api/inventory-items` | `?search=&page=&per_page=` → `{ items, meta }`; `packaged_quantity` = sum in pending delivery packages |
| `POST` | `/api/inventory-items` | create with `category_ids` and `components` |
| `PATCH` | `/api/inventory-items/{id}` | `quantity` (≥ 0) **ou** `quantity_add` / `quantity_subtract` (> 0; subtrair além do estoque → 422) / `name` / `unit_id` / `category_ids` (substitui todas; `[]` limpa) / `storage_id` |
| `POST` | `/api/inventory-items/{id}/build` | consumes the recipe and adds `quantity` (locks the component rows) |
| `GET` `POST` | `/api/delivery-packages` | `?status=pending\|delivered&page=&per_page=` → `{ items, meta }` / create `{ items: [{ id, quantity }], notes }` (only free stock: quantity − already packaged → 422) |
| `GET` `PUT` `DELETE` | `/api/delivery-packages/{id}` | show / replace `items` + `notes` (pending only) / delete (stock untouched) |
| `POST` | `/api/delivery-packages/{id}/deliver` | marks as delivered and subtracts the items from the stock (422 if delivered or short) |
| `GET` | `/up` | health check |

Everything except `/api/auth/login`, `/api/auth/logout`, `/api/auth/token/refresh` and `/up` requires `Authorization: Bearer <access_token>`.

## Authentication

Login and refresh return the session in the body:

```json
{ "user": { "id": 1, "name": "…", "email": "…" }, "access_token": "…", "refresh_token": "…", "expires_in": 900, "refresh_expires_in": 2592000 }
```

Opaque tokens (32 random bytes, not JWT); only their SHA-256 hash is persisted. The access token goes in `Authorization: Bearer`; the refresh token only in the body of `/api/auth/token/refresh` and `/api/auth/logout`. `expires_in` / `refresh_expires_in` are seconds until each token expires. Access tokens last 15 minutes, refresh tokens 30 days. Every refresh **rotates** the token inside its family; presenting an already rotated token revokes the whole family. Concurrent refreshes with the same token within 10 s (several tabs, SSR + browser) get the same new pair — the lock and the encrypted result live in the cache, shared by all php-fpm workers.

## Local setup

Requirements: Docker + Docker Compose (PHP and Composer run inside the containers).

```bash
cp .env.example .env   # fill SEED_ADMIN_NAME / SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD
make install
```

`make install` builds the images, starts `white-label-stock-api-nginx`, `-php` and `-postgres`, runs `composer install`, generates the key, migrates and seeds the admin user. The API answers at `http://localhost:$NGINX_PORT` (default `8005`); Postgres is published on `$FORWARD_DB_PORT` (default `5443`, also used by the web e2e setup). The `${DB_DATABASE}_testing` database is created on the first start of the Postgres volume.

| Command | Effect |
|---|---|
| `make up` / `make down` / `make restart` | Start / stop / restart the containers |
| `make shell` / `make in` | Shell in the PHP container / pick any running container |
| `make tinker` | Laravel Tinker (REPL) in the PHP container |
| `make migrate` / `make migrate-fresh` / `make db-seed` | Migrations / rebuild the database / seed |
| `make test` | Pest suite (`make test TEST="--filter=units"`) |
| `make recreate-testing-database` | Drop and recreate `${DB_DATABASE}_testing` |
| `make composer-require lib=vendor/package` | Add a dependency |
| `make clear` / `make cache` | Clear / build Laravel caches |
| `make logs` | Follow `storage/logs/laravel.log` |

## Deploy

1. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `DOCKER_COMPOSE_FILE=docker-compose.production.yml`, strong `DB_PASSWORD`, `NGINX_PORT`. The web app must reach the API at its `API_URL`.
2. `make build && make install` the first time, `make deploy` for updates.
3. Optional TLS on the API itself: set `server_name` in `.docker/nginx/conf.d/api.conf`, `CERTBOT_*` in `.env`, then `make certbot-run`.

## License

Same terms as the web app: free to use, modify and white-label, keeping the attribution footer on every screen of the web app.
