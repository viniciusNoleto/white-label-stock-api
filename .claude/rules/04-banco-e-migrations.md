---
paths:
  - "database/**"
  - "config/database.php"
  - "app/Actions/**"
  - "app/Models/**"
  - "app/Base/Helpers/sql.php"
---

# 04. Banco de dados e migrations

## Regras

### 4.1 O banco é o guardião final das invariantes

- Integridade referencial por **FK**, unicidade por **índice único**. Não fazer `exists()` antes de gravar: além da query extra, ele tem corrida. Grave e traduza o erro:

  | SQLSTATE | Significado | Tradução típica |
  |---|---|---|
  | 23503 | FK violada | ao excluir: 409 "em uso"; ao gravar: 422 "referência inexistente" |
  | 23505 | unique violado | 409/422 "já existe" |
  | 40P01 / 40001 | deadlock / serialização | retry (decidido pelo **SQLSTATE**, não pelo texto); depois 409 (rule 05) |
  | 55P03 | lock não obtido em `lock_timeout` | 409 **sem** retry (já esperou) |

  Helpers: `is_foreign_key_violation()`, `is_unique_violation()`, `is_concurrency_failure()`.
- Invariantes de valor viram **`CHECK`**: saldo ≥ 0, quantidade > 0, status no conjunto válido, consistência entre colunas (`status = 'delivered'` ⇔ `delivered_at IS NOT NULL`), autorreferência proibida, campo só válido para certo tipo.
- Os valores do `CHECK` ficam **escritos na migration** (não lidos do enum), porque a migration tem de gerar sempre o mesmo schema. Enum mudou? Migration nova.

### 4.2 Toda FK tem índice

O Postgres não cria índice para FK. Sem ele, excluir o pai varre a tabela filha inteira e joins ficam lentos. Ao criar FK, crie `->index()` na coluna (exceto quando ela já for prefixo de um índice composto ou PK).

### 4.3 Migrations: expand/contract

- Migration só **adiciona**: tabela, coluna **anulável** ou com default, índice, constraint.
- Remover/renomear coluna ou tabela é **contract**: só numa release posterior, depois de (1) o código parar de ler, (2) migrar os dados, (3) essa release estar estável.
- **Nunca editar migration já executada** em algum ambiente compartilhado. Corrige-se com migration nova. Isso inclui mudança só de estilo ou de comentário: o diff numa migration antiga faz o revisor perguntar se o schema mudou. Se o formatador quer mexer nela (ex.: as migrations do skeleton do framework), exclua o arquivo do formatador (`pint.json` › `notPath`) em vez de reformatar.
- `down()` funcional; testar `migrate` → `rollback` → `migrate` em banco com dados antes de subir.
- Se uma migration antiga violou a política, **documente** em vez de reescrever.

**Por quê:** com expand-only, o código da versão anterior roda no banco novo. Então o rollback é "voltar o código", sem restaurar banco nem perder dados escritos depois do deploy.

### 4.4 Histórico denormalizado (snapshot)

Registros históricos (movimentações, autor de um documento) guardam a FK (`nullOnDelete`) **e uma cópia** dos dados legíveis (código, nome, unidade, nome do usuário). Excluir o cadastro não apaga nem embaralha o histórico.

### 4.5 Tipos

- Decimal: `decimal(P,S)` + cast `decimal:S` (rule 03).
- Timestamps em UTC; datas de calendário em `date`.
- Status e tipos: `string` + Enum no PHP + `CHECK` no banco.
- Sequências de negócio (código `{SKU}-0001`) com contador na linha do pai, travada durante a geração. A trava da linha pai é `FOR NO KEY UPDATE`, não `FOR UPDATE` (rule 05.3b).

### 4.6 Timeouts no banco

Toda conexão sai com `lock_timeout` (ex.: 5 s) e `statement_timeout` (ex.: 30 s), configuráveis por env. No Laravel + Postgres, use `server_options` na conexão `pgsql` (`config/database.php`): vai no DSN como `-c nome=valor`, sem consulta extra por requisição. Lock que passa do tempo → SQLSTATE 55P03 → 409 "tente de novo" (rule 02); consulta longa → cancelada (57014) → 500 logado. Sem isso, um lock esquecido prende o worker do PHP até o `max_execution_time` e as requisições seguintes fazem fila. Teste com `SHOW lock_timeout`.

### 4.7 Excluir o pai quando os filhos estão "vazios"

Quando a FK impede excluir o pai por causa de filhos que não têm mais valor (ex.: lotes com quantidade zero), defina a política explicitamente na Action de exclusão: apague esses filhos e o pai **na mesma transação**; se o pai ainda estiver em uso por outra coisa, a FK falha, a transação volta e os filhos voltam junto (→ 409). O histórico continua no log (snapshot + `nullOnDelete`). Sem política, o pai fica impossível de excluir para sempre.

## Modelo de migration de constraints

```php
return new class extends Migration {

    private const CHECKS = [
        'items_quantity_non_negative' => ['items', 'quantity >= 0'],
        'orders_status_valid' => ['orders', "status IN ('pending', 'delivered')"],
    ];

    private const FOREIGN_KEY_INDEXES = [['items', 'storage_id'], ['products', 'unit_id']];

    public function up(): void {
        foreach (self::CHECKS as $name => [$table, $expr]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expr})");
        }
        foreach (self::FOREIGN_KEY_INDEXES as [$table, $column]) {
            Schema::table($table, fn(Blueprint $t) => $t->index($column));
        }
    }

    public function down(): void { /* inverso, na ordem inversa */ }
};
```

### 4.8 Teste de configuração do banco não depende do `.env` de quem roda

`SHOW lock_timeout` contra a conexão da aplicação compara com o valor do `.env` local: muda o `.env`, quebra o teste sem nada estar errado. Prove a **ligação** (config → DSN → sessão do Postgres) numa conexão própria, com valores escolhidos no teste:

```php
config(['database.connections.timeouts_check' => [
    ...config('database.connections.pgsql'),
    'server_options' => ['lock_timeout' => '7s', 'statement_timeout' => '45s'],
]]);

try {
    expect(DB::connection('timeouts_check')->scalar('SHOW lock_timeout'))->toBe('7s');
} finally {
    DB::purge('timeouts_check');
}
```

A conexão padrão do teste está dentro da transação do `RefreshDatabase` e não pode ser recriada no meio do teste; por isso a conexão separada. Para a conexão da aplicação, confira só que o timeout não está desligado (`!= '0'`).

### 4.9 Advisory lock: espaços de chave

`pg_advisory_xact_lock(bigint)` e `pg_advisory_xact_lock(int, int)` são espaços de chave separados. Use a forma de **uma** chave para locks únicos (constante nomeada: "edição de receita") e a de **duas** chaves para famílias de locks por valor: `(espaço, hashtext(valor))`, com o espaço numa constante (`CODE_PREFIX_LOCK_SPACE = 1`). Colisão de `hashtext` só serializa à toa dois valores diferentes; nunca deixa passar dois iguais.

## Checklist

- [ ] Nova FK com índice e `onDelete` pensado (`cascade`, `nullOnDelete` ou restrict + 409).
- [ ] Nova invariante de valor com `CHECK`.
- [ ] Migration só expande; nenhuma migration antiga editada (nem estilo).
- [ ] `lock_timeout`/`statement_timeout` configurados e testados.
- [ ] `down()` testado com dados.
- [ ] Erros 23503/23505 traduzidos na Action, não deixados virar 500.
- [ ] Histórico guarda snapshot legível.
- [ ] Teste de timeout/configuração do banco independente do `.env`.
- [ ] Advisory locks por valor com a forma de duas chaves e o espaço numa constante.
