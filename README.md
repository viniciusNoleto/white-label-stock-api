# White Label Stock API

REST API of the [White Label Inventory System](../white-label-inventory-system): units, item categories, storages, inventory items (with recipes/components and builds) and cookie-based sessions. Built with **Laravel 13** (PHP 8.5) and **PostgreSQL 16**.

The web app (Angular SSR) is a separate project. Its Express server proxies `/api/**` to this API, so the browser only ever talks to the web origin and the session cookies stay first-party.

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
| `POST` | `/api/auth/login` | sets the `access_token` / `refresh_token` cookies |
| `POST` | `/api/auth/logout` | revokes both tokens, clears the cookies |
| `GET` | `/api/auth/me` | `{ user }` (`null` for anonymous; 401 when only the refresh token is left) |
| `POST` | `/api/auth/token/refresh` | rotates the refresh token |
| `GET` `POST` | `/api/units` | list (by name) / create |
| `PUT` `DELETE` | `/api/units/{id}` | update / delete (409 when in use) |
| `GET` `POST` | `/api/item-categories` | list / create (`color_hex` = `#RRGGBB`) |
| `PUT` `DELETE` | `/api/item-categories/{id}` | update / delete |
| `GET` `POST` | `/api/storages` | list / create |
| `PUT` `DELETE` | `/api/storages/{id}` | update / delete |
| `GET` | `/api/inventory-items` | `?search=&page=&per_page=` → `{ items, meta }` |
| `POST` | `/api/inventory-items` | create with `category_ids` and `components` |
| `PATCH` | `/api/inventory-items/{id}` | `quantity` / `quantity_add` / `quantity_subtract` / `name` / `storage_id` |
| `POST` | `/api/inventory-items/{id}/build` | consumes the recipe and adds `quantity` (locks the component rows) |
| `GET` | `/up` | health check |

Everything except `/api/auth/**` and `/up` requires a session.

## Authentication

Opaque tokens (32 random bytes, not JWT) stored only as `httpOnly`, `SameSite=Lax` cookies (`Secure` in production); only their SHA-256 hash is persisted. Access tokens last 15 minutes, refresh tokens 30 days. Every refresh **rotates** the token inside its family; presenting an already rotated token revokes the whole family. Concurrent refreshes with the same token within 10 s (several tabs, SSR + browser) get the same new pair — the lock and the encrypted result live in the cache, shared by all php-fpm workers.

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
