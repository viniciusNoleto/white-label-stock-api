---
paths:
  - "app/**"
  - "routes/**"
---

# 01. Arquitetura em camadas (Actions + DTOs, sem Repository)

## Regra

O fluxo de escrita é sempre:

```
routes/api.php → Controller → FormRequest → DTO → Action → Model (Eloquent)
                     ↓
               Resource + envelope de resposta
```

Leituras simples usam Eloquent direto no controller; leituras complexas ou reutilizadas usam uma **Query**.

| Camada | Onde | Responsabilidade | Proibido |
|---|---|---|---|
| Controller | `app/Http/Controllers/{Domínio}/` | Montar o DTO, chamar a Action/Query, devolver o Resource no envelope | Regra de negócio, query complexa, transação |
| FormRequest | `app/Http/Requests/{Recurso}/` | Validar formato; `messages()` devolve **chaves** de tradução | Consultar regra de negócio |
| DTO | `app/Data/{Domínio}/{Recurso}/` | `fromRequest()` lendo `validated()`; normalizar (trim, lowercase, defaults, decimal, data) | Regra de negócio |
| Action | `app/Actions/{Domínio}/{Recurso}/{Verbo}{Recurso}Action.php` | **Uma** operação de escrita: transação, locks, regra, tradução de erro de banco | Receber `Request`, devolver `Response` |
| Query | `app/Queries/{Domínio}/{Nome}Query.php` | Leitura complexa/reutilizada; método `get()` | Escrever |
| Model | `app/Models/{Domínio}/` | `$table`, `$fillable`, `casts()`, relações tipadas, scopes | Lógica de fluxo |
| Resource | `app/Http/Resources/{Domínio}/` | Formato exato do JSON (snake_case, ordem das chaves) | Consultas extras (N+1) |
| Enum | `app/Enum/{Domínio}/{Nome}Enum.php` | Todo conjunto fixo de valores (status, tipos, motivos) | Strings mágicas espalhadas |
| Helpers | `app/Base/Helpers/*.php` (autoload `files`) | Funções puras globais: mensagens, parse de id/data, decimal, hash, SQLSTATE | Estado, I/O |

## Por quê

- **Action em vez de Service genérico:** um arquivo por caso de uso, nome no imperativo. Fácil achar, testar e revisar. Um service de 800 linhas vira depósito.
- **Sem Repository:** o Eloquent já é a abstração de dados. Um Repository por cima só duplica métodos e esconde as queries, inclusive locks e transações, que precisam estar visíveis no caso de uso.
- **DTO tipado:** a Action não sabe que existe HTTP, então pode ser chamada por comando, fila, teste ou outra Action.
- **Resource explícito:** o contrato JSON fica num lugar só e não muda por acidente quando o model ganha coluna.

## Como

### Controller

```php
class UnitController extends BaseController {

    public function store(SaveUnitRequest $request, CreateUnitAction $action): JsonResponse|Response {
        try {
            return self::successResponse(
                data: UnitResource::make($action->handle(SaveUnitDTO::fromRequest($request))),
                message: 'messages.units.created',
                status_code: 201
            );
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.units.create_error');
        }
    }

    public function index(Request $request): JsonResponse|Response {
        try {
            $dto = ListPageDTO::fromRequest($request);
            $paginator = Unit::query()->orderBy('name')->orderBy('id')
                ->paginate(perPage: $dto->per_page, page: $dto->page);

            return self::successResponse(data: self::paginated($paginator, UnitResource::class));
        } catch (Throwable $exception) {
            return self::returnError($exception, 'messages.units.fetch_error');
        }
    }
}
```

- Tipo de retorno nativo em todo método (`: JsonResponse|Response`) e `catch (Throwable)`, não `catch (Exception)`: um `TypeError` ou `Error` também vira o 500 com a mensagem específica da operação, em vez de escapar para o handler global com a mensagem genérica.
- Actions/Queries são **injetadas no método** (não no construtor do controller).
- Dados do usuário autenticado que a operação precisa (ex.: autor) são passados **explicitamente**: `$action->handle($dto, $request->user())`.
- Listagem paginada usa **um** helper (`BaseController::paginated`) que devolve `{ items, meta: { page, per_page, total, last_page } }`.

### Action

```php
final class CreateUnitAction {

    public function __construct(private readonly OtherAction $other) {}   // no máximo 2 níveis

    /**
     * O que faz, a regra, os erros possíveis (docblock verdadeiro).
     *
     * @throws Throwable
     */
    public function handle(SaveUnitDTO $dto): Unit {
        try {
            return DB::transaction(fn() => Unit::create([...]));
        } catch (QueryException $exception) {
            if (is_unique_violation($exception)) {
                throw new ApiException('messages.units.name_taken');
            }

            throw $exception;
        }
    }
}
```

- `final class`, **um** método público `handle()`, sufixo `Action`.
- Recebe DTO, ids ou Models; devolve Model, coleção, DTO ou `void`.
- Busca por id da rota: trait `FindsOrFailsByKey` → `X::findOrFailByKey($id, 'messages.x.not_found')`. Id inválido (`abc`, `-1`) também vira 404.

### Model

```php
class Item extends Model {

    protected $table = 'items';
    protected $fillable = [...];

    protected function casts(): array {
        return ['quantity' => 'decimal:4', 'expires_at' => 'date', 'status' => StatusEnum::class];
    }

    /** @return BelongsTo<Product, $this> */          // generics para a análise estática
    public function product(): BelongsTo { … }

    /** @param Builder<self> $query */
    #[Scope]
    protected function usable(Builder $query): void { … }   // scope tipado, nada de scopeX($query)
}
```

- Constantes ligadas ao model (ex.: relações carregadas em toda resposta) ficam **no model** (`Product::RESPONSE_RELATIONS`), não em classes soltas dentro de `Actions/`.
- Colunas com cast e colunas calculadas por scope ganham `@property`/`@property-read` no docblock da classe.

### Resource

```php
/** @mixin \App\Models\Inventory\Unit */                 // tipagem para Larastan
class UnitResource extends JsonResource {
    public function toArray(Request $request): array {
        return ['id' => $this->id, 'name' => $this->name, 'created_at' => $this->created_at];
    }
}
```

## Leituras agregadas e contratos do framework

### Filtros compartilhados num scope do model

Quando duas leituras respondem à mesma pergunta em formatos diferentes (listagem linha a linha e resumo somado), os filtros ficam **num scope só** do model, recebendo o DTO de filtros: `StockMovement::filteredBy(ListStockMovementsDTO $dto)`. Cada Query só acrescenta o que é dela (ordem + paginação; `select` + `groupBy`). Sem isso, um filtro novo entra na listagem e o resumo passa a responder outra coisa sem ninguém perceber.

```php
// Model
/** @param Builder<self> $query */
#[Scope]
protected function filteredBy(Builder $query, ListStockMovementsDTO $dto): void {
    $query->when($dto->type, fn(Builder $q, StockMovementTypeEnum $t) => $q->where('type', $t->value))
          ->when($dto->date_from, fn(Builder $q, string $d) => $q->where('created_at', '>=', business_day_start_utc($d)));
          // … todos os filtros
}

// Queries
StockMovement::query()->filteredBy($dto)->orderByDesc('created_at')->paginate(...);           // listagem
StockMovement::query()->filteredBy($dto)->select([...])->selectRaw('SUM(quantity)…')->groupBy([...])->get(); // resumo
```

### Resource de linha agregada

Coluna agregada ganha **alias que não colide com cast** do model (`SUM(quantity)::numeric(30, 4)::text AS total_quantity`, não `AS quantity`: o cast `decimal:4` reformataria/estouraria). O Resource lê com `$this->getAttribute('total_quantity')` e expõe com o nome do contrato (`quantity`). Agregado de dinheiro/quantidade não usa a precisão da coluna (`decimal(15,4)`): a soma pode passar dela.

### Contrato do framework substituído em `app/Base`

Quando o comportamento padrão do framework está errado para o seu banco (ex.: o detector de deadlock do Laravel lê o texto em inglês da mensagem), implemente o **contrato** do framework numa classe `final` em `app/Base/{Área}/` e registre no `AppServiceProvider::register()` (`$this->app->singleton(Contrato::class, SuaClasse::class)`), com o porquê no docblock e um teste que falha sem o binding. Nada de patch em `vendor/` nem de herdar e sobrescrever método protegido.

## Checklist

- [ ] Controller sem regra de negócio, com retorno nativo e `try/catch (Throwable)` → `returnError($e, 'messages.x.y_error')`.
- [ ] Action `final`, `handle()` único, sem `Request`/`Response`, transação dentro dela.
- [ ] DTO lê só `validated()` e normaliza; nenhuma regra de negócio.
- [ ] Toda relação e scope tipados; casts declarados; `@property` para colunas com cast.
- [ ] JSON definido por Resource; chaves snake_case; ids `number`.
- [ ] Conjunto fixo de valores = Enum.
- [ ] O teste de arquitetura (rule 08) cobre: Actions `final` com sufixo, Queries `final`, sem HTTP nas Actions.
- [ ] Leituras que respondem à mesma pergunta compartilham um scope de filtros.
- [ ] Agregados com alias próprio (sem colidir com cast) e precisão maior que a da coluna.
- [ ] Comportamento do framework trocado só por binding de contrato em `app/Base`, testado.
