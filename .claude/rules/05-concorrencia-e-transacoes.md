---
paths:
  - "app/Actions/**"
  - "app/Models/**"
  - "app/Base/Database/**"
  - "app/Base/Helpers/sql.php"
  - "tests/Concurrency/**"
---

# 05. Concorrência e transações

## Regras

### 5.1 Transação na Action

Toda operação que escreve em mais de uma linha/tabela roda inteira num `DB::transaction` **dentro da Action**. Falhou no meio → nada gravado. Actions auxiliares que dependem da transação de quem chama dizem isso no docblock ("deve rodar dentro de uma transação").

### 5.2 Trave antes de ler o que vai decidir

Quando a decisão depende do estado atual (saldo, status, "já entregue?"), leia com lock **dentro da transação** e decida depois. Exemplos: travar o documento antes de mudar o status (impede operação dupla); travar a linha pai antes de gerar a sequência de códigos (essa com `FOR NO KEY UPDATE`, ver 5.3b).

### 5.3 Uma ordem global de locks

Deadlock = duas transações pegando os mesmos locks em **ordens diferentes**. Regra:

- defina **uma** ordem total para cada tipo de recurso disputado (ex.: itens por `(product_id, id)`) e encapsule num scope nomeado (`lockedInStockOrder()`);
- **todo** caminho que trava mais de uma linha desse tipo usa o scope;
- quem percorre vários grupos percorre em ordem crescente do mesmo critério;
- documente no scope que ele é a única ordem permitida.

Antes de travar, filtre o que **não será usado** (saldo zero, vencido) no `WHERE`: menos linhas travadas, menos contenção. Em READ COMMITTED o Postgres reavalia o `WHERE` depois de esperar o lock, então o filtro é seguro.

### 5.3b Linha referenciada por FK: `FOR NO KEY UPDATE`, nunca `FOR UPDATE`

Uma ordem global para as linhas **filhas** não basta. Toda inserção (ou update da coluna da FK) numa tabela que referencia `pais` faz o Postgres pegar `FOR KEY SHARE` na linha do pai. Matriz de conflito dos locks de linha:

| Pedido \ Mantido | `FOR KEY SHARE` | `FOR SHARE` | `FOR NO KEY UPDATE` | `FOR UPDATE` |
|---|---|---|---|---|
| `FOR KEY SHARE` | — | — | — | **conflita** |
| `FOR NO KEY UPDATE` | — | conflita | conflita | conflita |
| `FOR UPDATE` | **conflita** | conflita | conflita | conflita |

Logo, quem trava o pai com `FOR UPDATE` (o `lockForUpdate()` do Laravel) bloqueia todo mundo que só quer **inserir um filho** que aponte para ele. Caso real que deu deadlock em todas as execuções do teste: a produção de P travava a linha de P (`FOR UPDATE`, para a sequência de códigos) e depois os itens do componente C; a entrega travava os itens de C e P e, ao gravar a movimentação de P (FK para `products`), pedia `FOR KEY SHARE` em P. Ciclo.

Regra:
- trave linha pai com `->lock('for no key update')`, encapsulado num scope com nome (`Product::lockedForWrite()`) e com o porquê no docblock;
- `FOR NO KEY UPDATE` continua serializando duas escritas no mesmo pai (conflita consigo mesmo), que é o que a sequência de códigos precisa;
- `UPDATE` comum que não muda coluna de chave já pega `NO KEY UPDATE` sozinho; `DELETE` e update de coluna única pegam `FOR UPDATE` (e aí esperar é o certo);
- ao desenhar um fluxo, liste **todas** as linhas que ele trava, inclusive as travadas implicitamente pelas FKs dos `INSERT`s.

### 5.4 Retry e 409

- Transações de estado disputado usam `DB::transaction($callback, attempts: config('database.transaction_attempts'))` (padrão 3, configurável por env).
- O callback não produz efeito externo (e-mail, fila, HTTP) antes do commit, porque pode rodar mais de uma vez.
- O que sobrar (40P01/40001/55P03) é respondido com **409** "nada foi gravado, tente de novo" e um `Log::warning`. Nunca 500.
- O retry do Laravel só age na transação **mais externa**. Consequência para testes: dentro do `RefreshDatabase` (que abre a transação de nível 1) **nunca** há retry; o teste de "o que é repetido" fica em `tests/Unit`, fora dessa transação.
- ** Retry decidido pelo SQLSTATE.** O detector padrão do Laravel (`ConcurrencyErrorDetector`) reconhece deadlock pelo **texto** ("deadlock detected"). Com o Postgres em outro idioma (`lc_messages = 'pt_BR'` → "impasse detectado"), o retry para de acontecer sem erro nenhum. Substitua o contrato por uma classe que olha `getCode()` (`QueryException` estende `PDOException` e traz o SQLSTATE):

  ```php
  final class ConcurrencyErrorDetector implements \Illuminate\Contracts\Database\ConcurrencyErrorDetector {
      public const RETRYABLE_SQLSTATES = ['40P01', '40001'];

      public function causedByConcurrencyError(Throwable $e): bool {
          return $e instanceof PDOException && in_array((string)$e->getCode(), self::RETRYABLE_SQLSTATES, true);
      }
  }
  // AppServiceProvider::register()
  $this->app->singleton(ConcurrencyErrorDetectorContract::class, ConcurrencyErrorDetector::class);
  ```
- `attempts:` em **toda** `DB::transaction` das Actions, não só nas "de estoque": a regra fica simples de revisar e um teste de arquitetura confere (conta `DB::transaction(` e `attempts: config(...)` por arquivo).

### 5.4b Espera com limite

`lock_timeout` no banco (rule 04.6): nenhuma transação espera um lock para sempre. O 55P03 resultante cai no mesmo 409 do 5.4. O retry **não** repete por lock timeout (só deadlock/serialização), o que é bom: quem esperou 5 s não deve esperar mais 10. Essa é uma decisão, não um acaso: escreva no detector (5.4) e no helper `is_concurrency_failure` que o 55P03 não é repetido e por quê, e tenha um teste que conta as execuções da transação (3 para 40P01, 1 para 55P03). Um comentário dizendo o contrário já existiu neste projeto, sem teste: comentário de garantia sem teste é hipótese.

### 5.5 Invariante sobre um conjunto: advisory lock

Quando a regra envolve um conjunto que nenhum lock de linha cobre (ex.: "o grafo de receitas não tem ciclo"), serialize só aquela operação com `SELECT pg_advisory_xact_lock(<constante>)`. O lock é liberado no fim da transação e só concorre com quem usa a mesma chave. Guarde a chave numa constante nomeada.

### 5.6 Deduplicação de requisições concorrentes

Requisições idênticas que chegam juntas e não podem ser executadas duas vezes (ex.: refresh de token pelo SSR e pelo browser):
- `Cache::lock($key)->block($janela, …)`;
- o primeiro executa e guarda o resultado (**criptografado**, se sensível) por uma janela curta;
- os seguintes devolvem o resultado guardado.

O cache tem de ser **compartilhado entre processos** (`database`/`redis`, nunca `array` em produção). Documente o custo de segurança da janela (rule 07).

## Por quê

Com isolamento READ COMMITTED (padrão do Postgres), duas transações enxergam o estado antigo uma da outra até o commit. Sem lock, as duas decidem com base no mesmo saldo e vendem a mesma unidade. Com locks em ordens diferentes, elas se bloqueiam em ciclo. A ordem global previne, o retry recupera, o 409 degrada com honestidade, e o teste com processos reais (rule 08) prova.

## Modelo

```php
// Model Product
/** Trava para escrita sem bloquear o FOR KEY SHARE das FKs (ver 5.3b). */
#[Scope]
protected function lockedForWrite(Builder $query): void {
    $query->lock('for no key update');
}

// Model Item
/** Única ordem de lock de itens no sistema: produto, depois id. */
#[Scope]
protected function lockedInStockOrder(Builder $query): void {
    $query->orderBy('items.product_id')->orderBy('items.id')->lockForUpdate();
}

// Action
return DB::transaction(function () use ($id) {
    $order = Order::query()->lockForUpdate()->find($id) ?? throw new ModelNotFoundException('messages.orders.not_found'); // ninguém insere filho de pedido aqui
    $product = Product::query()->lockedForWrite()->find($product_id);  // FOR NO KEY UPDATE: inserções com FK seguem livres
    if ($order->isDelivered()) throw new ApiException('messages.orders.already_delivered');

    $items = Item::query()->whereIn('id', $ids)->usable()->lockedInStockOrder()->get();
    // … decide e grava
}, attempts: config('database.transaction_attempts'));
```

### 5.7 Advisory lock por valor, sempre o último lock

O lock da linha pai serializa **um** pai. Quando dois pais diferentes disputam o mesmo recurso derivado (dois produtos com o mesmo prefixo de código: `P5` sem SKU × SKU `P5`), nenhum lock de linha cobre a disputa e o índice único devolve 422 a um usuário sem culpa. Serialize pelo **valor disputado**:

```php
DB::statement('SELECT pg_advisory_xact_lock(?, hashtext(?))', [self::CODE_PREFIX_LOCK_SPACE, $prefix]);
```

Regra para não criar deadlock: o advisory lock é o **último** lock da transação (depois da linha do pai e dos itens). Quem o segura não espera mais nada; logo não fecha ciclo, só faz esperar. Se precisar pegá-lo antes de outros locks, prove com um teste de concorrência sem retry.

### 5.8 Escritas que o cliente vai repetir: tudo ou nada

Se a operação tem duas escritas e o cliente repete quando dá erro, as duas ficam **na mesma transação**, mesmo vindo de Actions diferentes (a Action de fora abre a transação; as de dentro viram savepoint). Caso real: o refresh rotacionava o token (commit) e só depois criava o access token. Se a segunda escrita falhasse, o token antigo já estava revogado e a nova tentativa do cliente era tratada como **roubo** (detecção de reuso), deslogando o usuário por um erro de infraestrutura.

```php
private function rotate(string $refresh_token): ?SessionDTO {
    return DB::transaction(function () use ($refresh_token) {
        $rotated = $this->rotateRefreshToken->handle($refresh_token);   // tem a própria transação: vira savepoint
        // …
        return new SessionDTO(..., access_token: $this->createAccessToken->handle(...), ...);
    }, attempts: config('database.transaction_attempts'));
}
```

Cuidado com o caminho que **retorna** (sem exceção) depois de escrever, como a revogação da família na detecção de reuso: ele faz commit normalmente, e é o que se quer.

### 5.9 Lock de cache que estoura é contenção

`Cache::lock($k, $ttl)->block($espera, …)` lança `LockTimeoutException` quando a espera acaba. Isso é 409, não 500 (rule 02). Teste segurando o lock no próprio teste (`Cache::lock($k, 10)->get()`) com a espera reduzida por config (1 s).

## Checklist

- [ ] Decisão baseada em estado lido com `lockForUpdate` dentro da transação.
- [ ] Locks múltiplos via scope de ordem global; nenhum `orderBy('id')->lockForUpdate()` avulso.
- [ ] Linha que é alvo de FK travada com `FOR NO KEY UPDATE`; o fluxo novo listou também os locks implícitos das FKs.
- [ ] `lock_timeout` configurado; `attempts:` em toda transação (teste de arquitetura).
- [ ] `attempts:` nas transações disputadas; callback sem efeito externo.
- [ ] 40P01/40001 mapeados para 409.
- [ ] Teste com processos paralelos para cada garantia de concorrência (rule 08), **rodando sem retry**, e o teste **falha** se a proteção for removida.
- [ ] Retry decidido por SQLSTATE (binding do detector) e testado com mensagem em outro idioma; 55P03 sem retry, com teste.
- [ ] Advisory lock por valor como último lock, com teste de concorrência que falha sem ele.
- [ ] Escritas que o cliente repete na mesma transação (teste: falhar a segunda escrita e repetir com sucesso).
