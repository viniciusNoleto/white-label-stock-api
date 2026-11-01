# White Label Stock API — instruções para agentes

API REST do White Label Inventory System em **Laravel 13** (PHP 8.5) + PostgreSQL 16. O front (Angular) é o projeto irmão `../white-label-inventory-system`, que faz proxy de `/api/**` para cá — **o contrato JSON é consumido por ele sem adaptação**: não mude formato, nomes ou status sem alinhar o front.

PHP/Composer só existem nos containers: rode tudo com `make` (ou `docker compose -f docker-compose.local.yml exec php-fpm …`).

## Camadas (Actions + DTOs, sem Repository)

```
routes/api.php → Controller → FormRequest → DTO → Action → Model (Eloquent)
                        ↓                 (leituras: Eloquent direto ou Query)
                  Resource + Response::successResponse / returnError
```

| Camada | Onde | Regras |
|---|---|---|
| Controller | `app/Http/Controllers/{Domínio}/` | Estende `App\Base\Http\Controllers\BaseController`. Actions/Queries injetadas no método. Corpo em `try { return self::successResponse(...) } catch (Exception $e) { return self::returnError($e, 'messages.{recurso}.{ação}_error'); }`. Só monta o DTO, chama a Action e devolve o Resource. Leitura simples (`index` de Unit/Storage/ItemCategory) usa Eloquent direto. |
| FormRequest | `app/Http/Requests/{Recurso}/` | Estende `BaseRequest`. `messages()` devolve **chaves** de `lang/*/messages.php`, não textos — a primeira falha vira a mensagem do 422. `*` nas chaves não atravessa pontos (`components.*.id.*`). |
| DTO | `app/Data/{Domínio}/{Recurso}/` | `Spatie\LaravelData\Data` com `fromRequest(XRequest $request)` lendo `validated()`. Normalização (trim, lowercase, defaults, clamp) fica aqui; regra de negócio não. |
| Action | `app/Actions/{Domínio}/{Recurso}/{Ação}{Recurso}Action.php` (Auth: `app/Actions/Auth/`) | `final class`, um único método público `handle()`, nome no imperativo + sufixo `Action` (`CreateUnitAction`). Recebe DTO/ids/Models, nunca `Request`; retorna Model, coleção, DTO ou `void`, nunca `Response`. Usa Eloquent direto. Transações (`DB::transaction`), locks e tradução de erros de banco (FK `23503` → `ApiException`) ficam aqui. Pode injetar outra Action (no máx. 2 níveis). |
| Query | `app/Queries/{Domínio}/` | Leitura complexa ou reutilizada (paginação com eager loading, usuário pelo token). `final`, sufixo `Query` (`ListInventoryItemsQuery`), método `get()`. |
| Model | `app/Models/{Domínio}/` | `$table`, `$fillable`, `casts()`, relações e scopes. Scope: `#[Scope] protected function nome(Builder $query, …): void` com `@param Builder<self> $query` (nada de `scopeX($query)` sem tipo). Decimais saem como string (`"2.5000"`). Busca por id da rota: `use FindsOrFailsByKey` → `X::findOrFailByKey($id, 'messages.{recurso}.not_found')` (id inválido também vira 404). |
| Resource | `app/Http/Resources/{Domínio}/` | Define o shape exato do JSON (snake_case, ordem das chaves). |
| Enum | `app/Enum/{Domínio}/{Nome}Enum.php` | Backed enum para todo conjunto fixo de slugs. |
| Helpers | `app/Base/Helpers/*.php` | Funções globais (autoload `files` no `composer.json`): `api_message`, `parse_id`, `format_decimal`, `hash_token`, `is_foreign_key_violation`. |

## Respostas (`App\Base\Traits\Response`)

- Envelope sempre `{ success, message, data }`; `message` = `api_message($key)` → `{ "pt-br", "es-mx", "en-us" }`. Mensagem nova entra nos **três** arquivos `lang/{pt_BR,es_MX,en}/messages.php`.
- Erros sempre com `data: null`. Erro de regra de negócio: `throw new ApiException('messages.x.y', $status, $replace)`.
- Status: 400 JSON malformado · 401 sem sessão · 404 não encontrado · 409 em uso · 422 validação/regra · 500 inesperado (a mensagem específica vem do `returnError($e, 'messages.…_error')`).
- JSON sempre snake_case; ids `number`.

## Sessão

- Tokens opacos (`generate_opaque_token`) em cookies httpOnly (`AuthCookies`), só o `hash_token` no banco. Nunca devolva token no corpo.
- Rota protegida: dentro de `Route::middleware('auth')` em `routes/api.php` (`AuthenticateMiddleware` lê o cookie `access_token`).
- Refresh rotaciona com detecção de reuso (`RotateRefreshTokenAction`) e dedupe por 10 s (`RefreshSessionAction`, lock no cache). Não remova o dedupe: o SSR do web e o browser refrescam ao mesmo tempo.

## Testes (Pest)

- `tests/Feature/**` contra o Postgres `${DB_DATABASE}_testing` com `RefreshDatabase`; `TestCase` já liga `withCredentials()` (cookies em `getJson`).
- Sessão: `$this->withUnencryptedCookies(sessionCookies())`.
- Falha de banco: `failTable('tabela')` (em `tests/Pest.php`; renomeia a tabela dentro da transação do teste) e confira a mensagem `…_error`. Actions são `final` e não dá para mockar — não tire o `final` para isso.
- Todo endpoint/regra nova com teste. Rodar: `make test`.
