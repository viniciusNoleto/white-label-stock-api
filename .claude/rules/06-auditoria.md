---
paths:
  - "app/Actions/**"
  - "app/Models/Inventory/**"
  - "app/Queries/Inventory/**"
  - "tests/Pest.php"
---

# 06. Auditoria e registros imutáveis

## Regras

### 6.1 Toda mudança de estado relevante grava uma linha de histórico

- Uma Action dedicada (`Record{X}MovementsAction`) grava o histórico. As outras Actions a injetam e chamam.
- Grava na **mesma transação** da mudança: se a mudança cair, o histórico cai junto, e vice-versa.
- Grava **antes** de excluir o registro de origem (a FK precisa existir na inserção; depois vira `null` pelo `nullOnDelete` e o snapshot fica).
- A linha guarda:
  - tipo (Enum);
  - valor com sinal (+ entra, − sai, 0 só muda de lugar);
  - saldo depois;
  - snapshot legível (código, nomes, unidade, usuário);
  - FKs anuláveis;
  - timestamp.
- Histórico é **só inserção**: sem endpoint de edição nem exclusão.
- Reserva/intenção (ex.: item separado num pedido pendente) **não** é movimentação: só o que muda o saldo.

### 6.2 Garantir por estrutura, não por memória

Duas redes, e o README diz o alcance de cada uma:

1. **Estática (heurística).** Um teste de arquitetura lê o código das Actions: quem escreve no recurso auditado (insert/update/upsert/delete/decrement no model, `$model->save()/fill()/touch()`, ou injeta a Action de consumo) **tem de chamar** a Action de registro (`$this->recordMovements->handle(`), não só injetá-la. Outro teste proíbe escrita no recurso pelo query builder cru (`DB::table('items')->update(...)`) em qualquer lugar de `app/`. Isenções explícitas, num mapa `arquivo => motivo`. Pega o esquecimento comum; **não prova** que todos os caminhos dentro da Action registram.
2. **Dinâmica (comportamento).** Depois de **cada** teste de Feature (`afterEach` global no `Pest.php`), uma consulta reconcilia o estado com o ledger: para cada registro com histórico, a última linha deixou o saldo e a localização atuais, e, se ele nasceu com uma linha de entrada, a soma com sinal é o saldo. Qualquer escrita que pulou o log, venha de onde vier, quebra o teste que a exercitou. Um teste dedicado prova que a reconciliação detecta uma escrita por fora (e desfaz no fim).

A rede estática barra no review; a dinâmica é a garantia. Escrever "garantido pela suíte" sobre a estática sozinha é prometer mais do que se prova.

### 6.3 Registro de fato consumado é imutável

Documento que representa algo que aconteceu (pedido entregue, venda, baixa) **não é editado nem excluído**. A Action de exclusão trava o registro, verifica o estado e recusa (422) se ele está consumado. Correção se faz com **novo lançamento** (estorno, ajuste), nunca apagando.

### 6.4 Contexto que responde perguntas de negócio

- Quando o mesmo tipo de evento tem causas diferentes que o dono quer medir, grave a causa (Enum opcional, ex.: motivo da baixa: vencido/avaria/extravio/outro).
- Garanta no banco que ela só aparece no tipo certo (`CHECK`), indexe para a consulta típica (`type, reason, created_at`) e exponha filtro na listagem.
- Documentos registram **autor** (FK `nullOnDelete` + nome copiado) e, quando houver, **destinatário**.
- Campo criado para a métrica precisa aparecer no **fluxo principal e no cenário de ponta a ponta** (ex.: a baixa dos vencidos no cenário da padaria manda `reason: expired` e o teste lê a perda do mês de volta). Se o cliente atual ainda não manda o campo, o README diz isso e o motivo (compatibilidade), em vez de deixar a métrica zerada em silêncio.

### 6.5 Autor explícito

Prefira receber o autor como parâmetro da Action (`handle($dto, ?User $author)`) em vez de ler `Auth::user()` lá dentro. A Action fica utilizável por comando e fila e testável sem sessão. Se um ponto antigo ainda lê `Auth::user()`, documente no docblock.

## Por quê

Histórico só é confiável se for completo (nenhum caminho escapa), atômico (não registra o que não aconteceu), legível depois que os cadastros somem e intocável. As quatro peças juntas (Action única, mesma transação, teste de arquitetura e imutabilidade) dão isso. Sem a causa e o autor, o histórico não responde as perguntas que o dono faz ("quanto perdi por vencimento?", "quem montou?").

## Modelos

Reconciliação (rede dinâmica), chamada no `afterEach` dos testes de Feature:

```php
function stockLedgerViolations(): array {
    try {
        return array_map(fn($r) => (array)$r, DB::select(<<<'SQL'
            WITH ordered AS (
                SELECT item_id, balance, storage_id,
                       ROW_NUMBER() OVER (PARTITION BY item_id ORDER BY id DESC) AS from_last,
                       FIRST_VALUE(type) OVER (PARTITION BY item_id ORDER BY id) AS first_type,
                       SUM(quantity) OVER (PARTITION BY item_id) AS total
                FROM stock_movements WHERE item_id IS NOT NULL
            )
            SELECT items.code, items.quantity, ordered.balance, ordered.total
            FROM items JOIN ordered ON ordered.item_id = items.id AND ordered.from_last = 1
            WHERE ordered.balance <> items.quantity
               OR ordered.storage_id IS DISTINCT FROM items.storage_id
               OR (ordered.first_type IN ('entry', 'production') AND ordered.total <> items.quantity)
            SQL));
    } catch (Throwable) {
        return []; // banco indisponível de propósito (failTable, DB mockado): nada a conferir
    }
}

pest()->extend(TestCase::class)->use(RefreshDatabase::class)
    ->afterEach(fn() => expect(stockLedgerViolations())->toBe([]))
    ->in('Feature');
```

Como o `catch` engole erro, **um teste dedicado prova que a conferência funciona**: grava pelo endpoint, altera por fora, espera 1 violação, desfaz, espera 0.

Teste estático (rede heurística):

```php
it('makes every action that writes items call the movement recorder', function () {
    $writes = '/Item::query\(\)[^;]*->(insert|update|upsert|delete|decrement|increment)\(|\$item->(update|delete|fill|save|touch)\(|ConsumeItemStockAction \$/s';
    $exempt = [
        'ConsumeItemStockAction.php' => 'quem chama registra',
        'DeleteProductAction.php' => 'só apaga lotes zerados: o saldo não muda',
    ];
    // … falha se o arquivo casa $writes e não contém '$this->recordMovements->handle('
});
```

### 6.6 O ledger responde com totais, não só com linhas

Histórico que só lista obriga o cliente a somar páginas para responder "quanto perdi em novembro, em kg". Exponha um **resumo** com os mesmos filtros da listagem (scope compartilhado, rule 01):

- agrupe pelo **snapshot** (`product_name`, `unit_abbreviation`), não pelo cadastro atual: produto renomeado ou excluído aparece como era na época;
- devolva a soma **com sinal** (perda sai negativa) e a contagem de linhas (transferência tem quantidade zero, mas aconteceu);
- não pagine quando a cardinalidade é limitada por produto × tipo × motivo; diga isso no docblock;
- **deixe a lacuna visível:** baixa sem motivo aparece como `reason: null`. Enquanto o cliente não manda o motivo, o resumo mostra quanto da perda ainda não foi classificado, em vez de esconder.

## Checklist

- [ ] Toda Action que muda o recurso auditado registra, na mesma transação, antes de excluir.
- [ ] Reconciliação do ledger depois de cada teste de Feature, com o teste que prova que ela detecta violação.
- [ ] Teste de arquitetura cobrindo a regra (e validado contra uma violação proposital); README diz que ele é heurístico.
- [ ] Campos de métrica usados no cenário de ponta a ponta.
- [ ] Registros consumados sem update/delete (422).
- [ ] Snapshot legível + FKs `nullOnDelete`.
- [ ] Causa e autor gravados quando o negócio pergunta por eles.
- [ ] Existe resumo agregado do histórico com os filtros da listagem, agrupado pelo snapshot, com a lacuna (`null`) visível.
