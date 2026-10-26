# White Label Stock API — instruções para agentes

API REST do White Label Inventory System em **Laravel 13** (PHP 8.5) + PostgreSQL 16. O front (Angular) é o projeto irmão `../white-label-inventory-system`, que faz proxy de `/api/**` para cá — **o contrato JSON é consumido por ele sem adaptação**: não mude formato, nomes ou status sem alinhar o front.

PHP/Composer só existem nos containers: rode tudo com `make` (ou `docker compose -f docker-compose.local.yml exec php-fpm …`).

## Camadas (padrão do eventure-api)

```
routes/api.php → Controller → (FormRequest → DTO) → Service → Repository → Model
                        ↓
                  Resource + Response::successResponse / returnError
```

| Camada | Onde | Regras |
|---|---|---|
| Controller | `app/Http/Controllers/{Domínio}/` | Estende `App\Base\Http\Controllers\BaseController`. Services injetados no método. Corpo em `try { return self::successResponse(...) } catch (Exception $e) { return self::returnError($e, 'messages.{recurso}.{ação}_error'); }`. Sem regra de negócio. |
| FormRequest | `app/Http/Requests/{Recurso}/` | Estende `BaseRequest`. `messages()` devolve **chaves** de `lang/*/messages.php`, não textos — a primeira falha vira a mensagem do 422. `*` nas chaves não atravessa pontos (`components.*.id.*`). |
| DTO | `app/DTO/{Domínio}/{Recurso}/` | `Spatie\LaravelData\Data` com `fromRequest(XRequest $request)` lendo `validated()`. Normalização (trim, lowercase, defaults, clamp) fica aqui. |
| Service | `app/Services/{Domínio}/{Recurso}/{Ação}{Recurso}Service.php` | Uma ação por classe, método `execute()`. Dependências via construtor `private readonly`. Transações (`DB::transaction`) e tradução de erros de banco (FK `23503` → `ApiException`) ficam aqui. |
| Repository | `app/Repositories/{Domínio}/` | Estende `BaseRepository` (`setModel()` no construtor). Só acesso a dados. `find/update/delete` recebem `message_key` e lançam `ModelNotFoundException` (id inválido também). |
| Model | `app/Models/{Domínio}/` | `$table`, `$fillable`, `casts()`, relações e scopes. Decimais saem como string (`"2.5000"`). |
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
- Refresh rotaciona com detecção de reuso (`RotateRefreshTokenService`) e dedupe por 10 s (`RefreshSessionService`, lock no cache). Não remova o dedupe: o SSR do web e o browser refrescam ao mesmo tempo.

## Testes (Pest)

- `tests/Feature/**` contra o Postgres `${DB_DATABASE}_testing` com `RefreshDatabase`; `TestCase` já liga `withCredentials()` (cookies em `getJson`).
- Sessão: `$this->withUnencryptedCookies(sessionCookies())`.
- Falha de banco: `$this->mock(XRepository::class, fn($m) => $m->shouldReceive('metodo')->andThrow(new RuntimeException()))` e confira a mensagem `…_error`.
- Todo endpoint/regra nova com teste. Rodar: `make test`.
