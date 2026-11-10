# White Label Stock API — instruções para agentes

API REST do White Label Stock em **Laravel 13** (PHP 8.5) + PostgreSQL 16. O web (Angular) é o projeto irmão `../white-label-stock-web`, que faz proxy de `/api/**` para cá — **o contrato JSON é consumido por ele sem adaptação**: não mude formato, nomes ou status sem alinhar o web.

O README é a documentação para pessoas (contrato, garantias e que teste prova cada uma, deploy) e `docs/concurrency.md` explica os locks com os cenários de deadlock; este arquivo é o resumo das convenções para quem escreve código aqui. Em caso de divergência, o comportamento descrito no README e provado pelos testes vale; corrija o texto que estiver errado.

**Rules detalhadas** (regra, porquê, modelo de código e checklist) em [`.claude/rules/`](.claude/rules/README.md); o Claude Code carrega cada uma quando a tarefa toca os arquivos do `paths:` dela. Outros agentes: abram o arquivo do assunto antes de mexer.

| Rule | Quando abrir |
|---|---|
| [01 Arquitetura](.claude/rules/01-arquitetura-em-camadas.md) | camada nova, Controller/Action/Query/Resource |
| [02 Respostas e erros](.claude/rules/02-respostas-e-erros.md) | status, mensagem, exceção |
| [03 Validação e dados](.claude/rules/03-validacao-e-dados.md) | FormRequest, DTO, decimal, data |
| [04 Banco e migrations](.claude/rules/04-banco-e-migrations.md) | migration, constraint, SQLSTATE |
| [05 Concorrência](.claude/rules/05-concorrencia-e-transacoes.md) | transação, lock, retry (com [`docs/concurrency.md`](docs/concurrency.md)) |
| [06 Auditoria](.claude/rules/06-auditoria.md) | qualquer mudança de estoque, movimentações |
| [07 Autenticação](.claude/rules/07-autenticacao-e-seguranca.md) | tokens, senha, rate limit |
| [08 Testes](.claude/rules/08-testes.md) | todo teste novo |
| [09 Qualidade e CI](.claude/rules/09-qualidade-e-ci.md) | CI, Dockerfile, ferramentas |
| [10 Operação](.claude/rules/10-operacao-e-deploy.md) | Makefile, `scripts/`, deploy, logs |
| [11 Documentação](.claude/rules/11-documentacao.md) | README, AGENTS.md, `docs/` |

PHP/Composer só existem nos containers: rode tudo com `make` (ou `docker compose -f docker-compose.local.yml exec php-fpm …`).

## Camadas (Actions + DTOs, sem Repository)

```
routes/api.php → Controller → FormRequest → DTO → Action → Model (Eloquent)
                        ↓                 (leituras: Eloquent direto ou Query)
                  Resource + Response::successResponse / returnError
```

| Camada | Onde | Regras |
|---|---|---|
| Controller | `app/Http/Controllers/{Domínio}/` | Estende `App\Base\Http\Controllers\BaseController`. Actions/Queries injetadas no método. Métodos com retorno nativo `: JsonResponse|Response`. Corpo em `try { return self::successResponse(...) } catch (Throwable $e) { return self::returnError($e, 'messages.{recurso}.{ação}_error'); }` (`Throwable`, não `Exception`: `TypeError`/`Error` também viram o 500 com a mensagem do recurso). Só monta o DTO, chama a Action e devolve o Resource. Leitura simples (`index` de Unit/Storage/ItemCategory) usa Eloquent direto. |
| FormRequest | `app/Http/Requests/{Recurso}/` | Estende `BaseRequest`. `messages()` devolve **chaves** de `lang/*/messages.php`, não textos — a primeira falha vira a mensagem do 422. `*` nas chaves não atravessa pontos (`components.*.id.*`). |
| DTO | `app/Data/{Domínio}/{Recurso}/` | `Spatie\LaravelData\Data` com `fromRequest(XRequest $request)` lendo `validated()`. Normalização (trim, lowercase, defaults, clamp) fica aqui; regra de negócio não. |
| Action | `app/Actions/{Domínio}/{Recurso}/{Ação}{Recurso}Action.php` (Auth: `app/Actions/Auth/`) | `final class`, um único método público `handle()`, nome no imperativo + sufixo `Action` (`CreateUnitAction`). Recebe DTO/ids/Models, nunca `Request`; retorna Model, coleção, DTO ou `void`, nunca `Response`. Usa Eloquent direto. Transações (`DB::transaction(…, attempts: config('database.transaction_attempts'))`, sempre com `attempts`: o `ArchitectureTest` confere), locks e tradução de erros de banco (FK `23503` → `ApiException`) ficam aqui. Pode injetar outra Action (no máx. 2 níveis). |
| Query | `app/Queries/{Domínio}/` | Leitura complexa ou reutilizada (paginação com eager loading, usuário pelo token). `final`, sufixo `Query` (`ListItemsQuery`), método `get()`. |
| Model | `app/Models/{Domínio}/` | `$table`, `$fillable`, `casts()`, relações e scopes. Scope: `#[Scope] protected function nome(Builder $query, …): void` com `@param Builder<self> $query` (nada de `scopeX($query)` sem tipo). Decimais saem como string (`"2.5000"`). Busca por id da rota: `use FindsOrFailsByKey` → `X::findOrFailByKey($id, 'messages.{recurso}.not_found')` (id inválido também vira 404). |
| Resource | `app/Http/Resources/{Domínio}/` | Define o shape exato do JSON (snake_case, ordem das chaves). |
| Enum | `app/Enum/{Domínio}/{Nome}Enum.php` | Backed enum para todo conjunto fixo de slugs. |
| Helpers | `app/Base/Helpers/*.php` | Funções globais (autoload `files` no `composer.json`): `api_message`, `parse_id`, `parse_date`, `business_today`, `to_decimal`, `decimal_rule`, `format_decimal` (bcmath, nunca float), `DECIMAL_SCALE` (a escala de **toda** conta bcmath: nada de `SCALE = 4` por classe nem `bcadd(…, 4)`; o `ArchitectureTest` confere), `DECIMAL_MAX`, `hash_token`, `escape_like` (toda busca com `whereLike` passa por ele), `is_foreign_key_violation`, `is_concurrency_failure`. |

## Domínio do estoque

- **Produto** (`products`): o modelo — unidade, categorias, receita (`product_has_component`), `shelf_life_days` e `is_atomic`.
- **Item** (`items`): registro físico com `code` único, sempre gerado pela API (`{SKU}-0001`…) e nunca editável. Atômico → `quantity` = 1 (um item por unidade); a granel → um lote com quantidade. `expires_at` = `produced_at` + validade, gravado na criação. `produced_at` nunca no futuro (`before_or_equal:` + `business_today()` no FormRequest) e `expires_at >= produced_at` (na Action, contra o que já está gravado, porque o PATCH é parcial).
- Validação nunca descarta em silêncio: todo elemento de lista tem regra (`category_ids.*`, `items.*.item_ids.*`) e o DTO só converte o que o FormRequest já validou (nada de `->filter()` jogando fora o inválido e respondendo 200).
- Decimais (`decimal(15,4)`): validação com `decimal_rule()` (máx. 4 casas e 11 dígitos inteiros) e normalização com `to_decimal()` no DTO; contas sempre com `bcmath` em string. Nunca `(float)`/`number_format` em quantidade. Resultado de conta (soma de parcelas válidas) pode passar de `DECIMAL_MAX`: confira antes de gravar (→ 422 `messages.items.quantity_too_large`), senão o Postgres devolve 22003 (500).
- "Hoje" é `business_today()` (fuso `BUSINESS_TIMEZONE`), nunca `now()->toDateString()`: vale para vencido, produção padrão, janela "vencendo" e filtros por dia (`business_day_start_utc`). Timestamps continuam em UTC.
- Saída de estoque (consumo da receita e reserva em pacote) sempre por `AllocateProductStockAction`: só o livre (− reservado em pacotes pendentes), nunca vencido, em ordem FEFO, com os itens travados. No pacote, um produto atômico pode trazer `item_ids` (itens escolhidos um a um) → `AllocateSelectedItemsAction` (mesmas regras: livre, não vencido, travado); com `item_ids`, `quantity` é opcional (o DTO usa a contagem) e, se vier, precisa bater. `ConsumeItemStockAction` exclui o atômico e subtrai o lote.
- **Locks**, duas regras (há teste em `tests/Concurrency` que reproduz o deadlock de cada uma quando violada):
  - itens: sempre `Item::lockedInStockOrder()` (produto, depois id), percorrendo produtos em ordem de id. Qualquer outra ordem cria deadlock com a reserva/entrega;
  - linha de `products`: sempre `Product::lockedForWrite()` (`FOR NO KEY UPDATE`), **nunca** `lockForUpdate()` (o `ArchitectureTest` procura `Product::query()…->lockForUpdate()`). Toda inserção com FK para `products` pede `FOR KEY SHARE`, que só conflita com `FOR UPDATE`: produção de P consumindo C × entrega de C + P fechava ciclo.
  - advisory lock (`pg_advisory_xact_lock`) é sempre o **último** lock da transação, ou documentado como seguro. Hoje: o prefixo do código do item em `CreateItemsAction::nextCodes` (duas chaves: espaço `1` + `hashtext(prefixo)`; produto sem SKU `P5` × produto com SKU `P5`) e a edição de receita (uma chave, `SyncProductRelationsAction`).
  - Transação que mexe em estoque usa `attempts`. O retry é decidido por SQLSTATE em `App\Base\Database\ConcurrencyErrorDetector` (40P01 e 40001; o detector do Laravel lia o texto em inglês e falhava com o Postgres em outro idioma). 55P03 **não** é repetido (já esperou o `lock_timeout`). O que sobrar vira 409 `messages.concurrency_conflict` no `Response`, assim como `LockTimeoutException` de lock de cache. Retry só acontece na transação de nível 1: em teste de Feature (dentro do `RefreshDatabase`) nunca há retry; teste de retry vai em `tests/Unit`. `lock_timeout` (5 s → 55P03 → 409) e `statement_timeout` (30 s) vão no DSN (`config/database.php` › `server_options`).
- Pacote de entrega guarda linhas por item (`DeliveryPackageLine`, com `item_code`): o atômico entregue é excluído e a linha mantém o código. A entrega confere a validade de novo (item vencido desde a reserva → 422). Pacote entregue não é editado nem excluído (422). `recipient` e o autor (`created_by_user_id`/`_name`, copiado) ficam no pacote.
- Operação (`ListStockQuery`): atômicos agrupados por produto, lotes a granel um por linha.
- Movimentações (`stock_movements`, `GET /api/stock-movements`): toda alteração de estoque grava uma linha via `RecordStockMovementsAction`, na mesma transação e **antes** de excluir o item — entrada/produção (`CreateItemsAction`), consumo da receita, ajuste e transferência (`UpdateItemAction`), entrega (`DeliverDeliveryPackageAction`) e baixa (`DeleteItemAction`). Reserva em pacote pendente não é movimentação. `quantity` com sinal, `balance` = saldo do item depois; código, nomes e usuário copiados na linha (FKs `nullOnDelete`). Listagem e resumo (`GET /api/stock-movements/summary`, totais por produto/tipo/motivo, agrupados pelos nomes copiados) usam os mesmos filtros: o scope `StockMovement::filteredBy($dto)`. Operação nova que mexa em quantidade ou armazenamento de item precisa registrar também. Duas redes: `tests/Unit/ArchitectureTest.php` (estática, heurística: Action que escreve em `items` sem chamar `$this->recordMovements->handle(`, ou `DB::table('items')` com escrita) e `stockLedgerViolations()` depois de **todo** teste de Feature (dinâmica: estado do item × ledger). Nunca ajuste item "por fora" num teste de Feature depois de uma movimentação sem desfazer.
- **Painel** (`GET /api/dashboard?period=7|30|90`, `DashboardController` → `GetDashboardQuery` → `DashboardStatsQuery`): estatísticas da página inicial, sem rate limit. Cache versionado em `App\Base\Cache\DashboardCache`: vale enquanto a versão, o dia do negócio e `dashboard.max_age_seconds` permitirem; uma reconstrução por vez (lock de cache), quem chega no meio recebe a entrada anterior do mesmo dia, e sem entrada espera até `build_wait_seconds` (relógio monotônico, nunca `Lock::block()`, que usa `now()`) e então calcula sozinho — nunca 409/429. **Toda escrita que muda estoque, pacote ou produto chama `DashboardCache::invalidate()` dentro da transação** (troca a versão no `afterCommit`); estoque já está coberto por `RecordStockMovementsAction`. Totais gerais são contagens; quantidade só por produto (unidades diferentes).
- Exclusão de produto apaga junto os lotes a granel **zerados** (sem movimentação: o saldo não muda); com saldo, receita ou pacote → 409. Baixa (`DELETE /api/items/{id}`) aceita `reason` (`WriteOffReasonEnum`), gravado na movimentação.

## Respostas (`App\Base\Traits\Response`)

- Envelope sempre `{ success, message, data }`; `message` = `api_message($key)` → `{ "pt-br", "es-mx", "en-us" }`. Mensagem nova entra nos **três** arquivos `lang/{pt_BR,es_MX,en}/messages.php`.
- Erros sempre com `data: null`. Erro de regra de negócio: `throw new ApiException('messages.x.y', $status, $replace)`.
- Status: 400 JSON malformado · 401 sem sessão · 403 sem permissão (rota de admin para usuário comum; login de cadastro ainda pendente) · 404 não encontrado · 409 em uso ou conflito de concorrência · 422 validação/regra · 429 rate limit · 500 inesperado (a mensagem específica vem do `returnError($e, 'messages.…_error')`) · 503 manutenção (deploy).
- Toda resposta leva `X-Request-Id` (`AssignRequestIdMiddleware`, também no `Context` dos logs). `ApiException` não é logada (é regra de negócio).
- JSON sempre snake_case; ids `number`.

## Sessão

- Tokens opacos (`generate_opaque_token`), só o `hash_token` no banco. Login e refresh devolvem a sessão no corpo (`SessionResource`: `user`, `access_token`, `refresh_token`, `expires_in`, `refresh_expires_in`). A API não lê nem grava cookies.
- Rota protegida: dentro de `Route::middleware('auth')` em `routes/api.php` (`AuthenticateMiddleware` lê `Authorization: Bearer` via `$request->bearerToken()`). Refresh (`refresh_token` no corpo) e logout (Bearer + `refresh_token` opcional no corpo) ficam fora do `auth`.
- Administração: `users.is_admin`. Rotas só de admin ficam no grupo `Route::middleware('admin')` (dentro do `auth`; `EnsureAdminMiddleware` → 403 `messages.forbidden`). O admin inicial vem do seeder (`SEED_ADMIN_*`).
- Cadastro público: `POST /api/auth/register` cria um `registration_requests` (senha já com hash), não um usuário. Admin aprova (`POST /api/registration-requests/{id}/approve` → cria o `User` comum com a mesma senha e exclui o pedido) ou recusa (`DELETE /api/registration-requests/{id}` → exclui o pedido). Login com o pedido ainda pendente (senha certa) → 403 `messages.auth.pending_approval`.
- Usuários: `GET /api/users`, `DELETE /api/users/{id}` (admin nunca é excluído → 422; os tokens saem em cascata, o acesso acaba na hora), `PUT /api/users/{id}/password` (admin redefine a senha de usuário comum; de outro admin → 422).
- Senha: `PUT /api/auth/password` (`current_password`, `password`; `throttle:password`) encerra todas as sessões e devolve uma nova (`SessionResource`). Toda troca de senha passa por `SetUserPasswordAction` (grava e apaga todos os tokens). Admin que esqueceu a senha: `php artisan user:reset-password {email}` no servidor.
- Login, cadastro e refresh têm rate limit (`throttle:login|register|refresh`, limites em `AppServiceProvider`). O IP vem do `X-Forwarded-For` só de proxy em rede privada (`config/trustedproxy.php`); nunca volte para `*`. O login faz exatamente um `Hash::check` em todo caminho (hash descartável quando o e-mail não existe) para não vazar quais contas existem pelo tempo.
- Refresh rotaciona com detecção de reuso (`RotateRefreshTokenAction`) e dedupe por 10 s (`RefreshSessionAction`, lock no cache). Rotação e access token novo ficam **na mesma transação** (falha no access token desfaz a rotação; senão a nova tentativa caía na detecção de reuso e deslogava o usuário). Não remova o dedupe: o SSR do web e o browser refrescam ao mesmo tempo (trade-off documentado no README). Access token guarda o `family_id` do refresh emitido junto: no reuso, os access tokens da família também caem.

## Migrations

- Só expandem (expand/contract): coluna nova anulável, tabela, índice, `CHECK`. Remover coluna/tabela só numa release posterior, depois de migrar os dados e de nenhum código em produção ler. Nunca editar migration já executada (nem estilo: as do skeleton do Laravel ficam fora do Pint em `pint.json` › `notPath`).
- Valores de `CHECK` ficam escritos na migration (não lidos de enum). FK nova ganha índice.

## Qualidade

- `make ci` = `make lint` (Pint, `pint.json`) + `make analyse` (Larastan nível 5, sem baseline) + `make audit` + `make test` (`composer ci` faz o mesmo). O GitHub Actions roda o mesmo (`.github/workflows/ci.yml`, com `timeout-minutes`); os testes rodam **dentro da imagem de produção** (`.docker/php/Dockerfile` + pcov via `INSTALL_PCOV=1`) com cobertura mínima de 95% (`composer test-coverage` / `make test-coverage`).
- `Model::preventLazyLoading()` fora de produção: relação usada sem `with()` numa coleção quebra o teste.
- Mudança chega por branch + PR (template em `.github/pull_request_template.md`), um assunto por PR, commit dizendo o porquê.
- Versões fixas: imagens Docker por tag exata, Actions por SHA (tag no comentário), `composer.lock`. Quem atualiza é o Dependabot (`.github/dependabot.yml`), via PR.

## Operação

- `make deploy` (ou `make deploy REF=<tag>` para rollback): manutenção → backup → código → build → composer → migrate → cache → restart do php-fpm (opcache sem revalidação em produção) → no ar → tag `deploy-AAAAMMDD-HHMMSS`. Não mude a ordem: o backup precisa sair com a API parada e o restart é o que troca o bytecode.
- A tag `deploy-*` é enviada para `DEPLOY_REMOTE` (padrão `origin`): o servidor precisa de permissão de push. Falha no push não desfaz o deploy, só avisa.
- Scripts de operação em `scripts/*.sh` (`set -euo pipefail`, funções comuns em `scripts/lib.sh`, shellcheck limpo); o Makefile só chama e exporta as variáveis do `.env`. Lógica de mais de uma linha não vai para o Makefile.
- `make backup` (retenção `BACKUP_KEEP`, cópia `BACKUP_OFFSITE`; `BACKUP_PRUNE=0` não apaga antigos; grava em `.partial` e renomeia no fim), `make backup-verify` (restaura num banco descartável), `make restore file=…` (confirmação → manutenção → dump de segurança do estado atual, sem poda → restore; falhou, fica em manutenção e mostra o dump para voltar).
- Logs: `request_id` (`AssignRequestIdMiddleware`) e `user_id` (`AuthenticateMiddleware`) no `Context`, logo em todo log da requisição. Produção: `LOG_STACK=stderr` + `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`; `LOG_SLACK_WEBHOOK_URL` preenchido põe o canal `slack` na pilha sozinho (nível `LOG_SLACK_LEVEL`, padrão `error` = os 500). Rotação dos logs dos containers no `docker-compose.production.yml` (`x-logging`).

## Testes (Pest)

- `tests/Feature/**` contra o Postgres `${DB_DATABASE}_testing` com `RefreshDatabase` (o nome sai do `.env`, em `tests/TestCase.php`). Os testes fixam `BUSINESS_TIMEZONE=UTC`; testes de fuso trocam `app.business_timezone`.
- `tests/Concurrency/**`: processos paralelos de verdade (`runConcurrently()` em `tests/Pest.php`, com `pcntl_fork` e SIGKILL no filho que passa de 30 s) e `DatabaseTruncation`. Regra nova de lock/estoque concorrente ganha teste aqui, dentro de `withoutTransactionRetries()` (sem retry, um deadlock aparece como falha `40P01` no resultado do processo). Confirme que o teste falha sem a correção.
- `tests/Feature/**`: depois de cada teste, `stockLedgerViolations()` precisa voltar vazio (ver Movimentações).
- `tests/Unit/ArchitectureTest.php`: convenções verificadas (Actions `final` e sem HTTP, `attempts` em toda transação, chamada do registro de movimentação, nada de `DB::table('items')` com escrita, sem `dd`/`env()` fora de config, bcmath só com `DECIMAL_SCALE`, nada de `lockForUpdate()` em `Product`).
- `tests/Unit/TransactionRetryTest.php`: o que o `DB::transaction(…, attempts)` repete (precisa estar fora da transação do `RefreshDatabase`).
- Sessão: `$this->withToken(accessToken())` (`accessToken()` em `tests/Pest.php`).
- Falha de banco: `failTable('tabela')` (em `tests/Pest.php`; renomeia a tabela dentro da transação do teste) e confira a mensagem `…_error`. Actions são `final` e não dá para mockar — não tire o `final` para isso.
- Todo endpoint/regra nova com teste. Rodar: `make test`.
