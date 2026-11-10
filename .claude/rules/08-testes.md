---
paths:
  - "tests/**"
  - "phpunit.xml"
---

# 08. Testes

## Regras

### 8.1 Feature tests contra o banco real

- Pest, `tests/Feature/**`, contra o **mesmo SGBD de produção** (Postgres), banco `${DB_DATABASE}_testing`, com `RefreshDatabase` (transação por teste).
- O nome do banco de teste é derivado do `.env` em `TestCase::createApplication()` (acrescenta `_testing`), nunca fixo no `phpunit.xml`, e nunca aponta para o banco de desenvolvimento.
- Todo endpoint e toda regra nova têm teste. A ordem típica num `describe` por rota:
  1. caminho feliz com o **formato exato** (`array_keys` do JSON);
  2. validação (dataset de payloads inválidos → mensagem exata);
  3. regras de negócio;
  4. 404 (id inexistente **e** inválido);
  5. 409;
  6. 500.
- Sessão: helper `accessToken($user = null)` + `withToken()`. Ao avançar o relógio além da validade do token, emita um token novo.

### 8.2 Caminho de erro sem mock

`failTable('tabela')` renomeia a tabela dentro da transação do teste. A operação falha de verdade e o teste confere a mensagem `…_error` e o rollback. Classes `final` não são mockadas, e não se tira o `final` para testar.

### 8.3 Cenários de ponta a ponta como documentação

`tests/Feature/Scenarios/` conta uma história de usuário real (ex.: uma confeitaria do cadastro à entrega), com `Carbon::setTestNow`. É documentação executável do produto.

### 8.4 Concorrência com processos reais

- Suíte separada (`tests/Concurrency`) com `DatabaseTruncation` (dados commitados, visíveis a outros processos).
- Helper `runConcurrently($workers, $work)`:
  1. `DB::disconnect()` no pai;
  2. `pcntl_fork()` N vezes, com largada sincronizada (`time_sleep_until`);
  3. cada filho roda o trabalho, serializa o resultado ou o erro (classe, chave de negócio, SQLSTATE) num arquivo e termina com `SIGKILL`;
  4. o pai espera e agrega.
- Asserções sobre **invariantes finais** (nada vendido duas vezes, saldo ≥ 0, contagens exatas).
- Prova de "não há deadlock": rode a corrida **sem retry** (`withoutTransactionRetries(fn() => runConcurrently(...))`, que põe `attempts = 1` antes do fork). Um deadlock vira falha `40P01` no resultado do processo e o teste aponta qual. Isso substitui ler `pg_stat_database.deadlocks`, que dependia de esperar ~1 s pelas estatísticas e contava deadlocks do banco inteiro (quebra com testes em paralelo).
- O pai espera os filhos com `pcntl_waitpid(..., WNOHANG)` até um prazo (ex.: 30 s) e mata com `SIGKILL` quem passar; o filho volta como `['ok' => false, 'error' => 'timeout']`. Um lock preso falha o teste em vez de pendurar a suíte.
- Use a mesma infraestrutura de produção (ex.: cache `database` para locks), não a de teste (`array`).
- **Valide o teste contra o bug:** remova a proteção temporariamente e confirme que ele falha. Teste de concorrência que nunca falhou não prova nada.

### 8.4b Invariante conferida depois de todo teste

Quando o sistema tem um invariante global barato de conferir (ledger × estado, soma de lançamentos = saldo), coloque a conferência num `afterEach` da suíte de Feature. Todo teste vira também um teste do invariante, inclusive os que ninguém escreveu pensando nele. Regras: a conferência ignora banco indisponível de propósito (`failTable`, mock) e existe um teste que prova que ela detecta a violação (rule 06.2).

### 8.5 Testes de arquitetura

`tests/Unit/ArchitectureTest.php` (Pest Arch) transforma convenções em verificação:
- Actions `final`, sufixo `Action`, sem `Request`/`Response`;
- Queries `final`, sufixo `Query`;
- sem `dd`/`dump`/`ray`/`var_dump`/`print_r`;
- `env()` só em `config`;
- regras de domínio por varredura de código (ex.: rule 06.2);
- `attempts:` em toda transação das Actions.
- contas bcmath só com a escala central (`DECIMAL_SCALE`);
- nenhum `Product::query()…->lockForUpdate()` (a linha do produto só com `lockedForWrite()`).

Teste de arquitetura que lê código é heurística: diga isso no nome/comentário e no README, e tenha a garantia de comportamento em outro lugar (8.4b).

### 8.6 Teste honesto

O nome do teste diz exatamente o que ele prova. Um teste sequencial não se chama "concurrent". Se a prova real está em outro lugar, o comentário aponta para lá.

### 8.7 Determinismo

- Relógio fixo (`Carbon::setTestNow`) e fuso de negócio fixo no `phpunit.xml`; testes de fuso trocam a config explicitamente e testam a borda (22h e 00h01 locais).
- Datasets para bordas numéricas (rule 03).
- Mock só de fronteira (ex.: contar `Hash::check`, simular falha de `SELECT 1` com `DB::partialMock()`), nunca de Action.

### 8.8 Provar a falha, retries, logs e cobertura

- **Prove que o teste falha sem a correção, toda vez.** Exemplos neste projeto: o teste do refresh (voltando o arquivo do `HEAD` com `git show HEAD:arquivo > arquivo`), o do detector de retry (comentando o binding), o de colisão de código (rodado antes de escrever a correção: vários processos com `code_taken`) e o de log com `user_id` (comentando o `Context::add`). Anote no PR **como** falhou: às vezes falha por outro motivo (no refresh, sem savepoint o Postgres abortou a transação do teste inteira), e é bom saber.
- **Teste do que o framework repete fica fora da transação do teste** (`tests/Unit`): monte a `QueryException` com o SQLSTATE que o Postgres mandaria (subclasse anônima de `PDOException` que grava `$this->code`, porque o construtor só aceita `int`) e conte quantas vezes o callback do `DB::transaction` rodou.
- **Log com contexto testado de verdade:** empurre um `Monolog\Handler\TestHandler` no logger (`Log::getLogger()->pushHandler($handler)`), provoque o 500 e confira `extra.request_id` e `extra.user_id` no registro de nível `Error`. `Log::spy()` não serve aqui: o `Context` entra pelo processor do Monolog, depois do facade.
- **Lock segurado pelo próprio teste** para provar o 409 de contenção (cache: `Cache::lock(...)->get()` + espera reduzida por `config()`).
- **Teste independente do `.env`** (rule 04.8).
- **Cobertura com piso:** `pest --coverage --min=95` no CI (pcov). O piso é um alarme de regressão (ninguém apaga teste sem perceber), não uma meta; meça antes e ponha o piso um pouco abaixo do real.
- **`Model::preventLazyLoading(!app()->isProduction())`**: relação usada sem `with()` numa coleção quebra o teste que passa por ela. Ligue e rode a suíte inteira; o que quebrar é N+1 real.

## Checklist

- [ ] Endpoint novo: sucesso com formato, validação, regra, 404, 409, 500.
- [ ] Garantia de concorrência com teste em `tests/Concurrency`, sem retry, validado contra o bug (rode com a proteção removida e veja falhar).
- [ ] Invariantes globais no `afterEach`, com o teste que prova a detecção.
- [ ] Convenção nova virou teste de arquitetura quando dá.
- [ ] Nomes de teste verdadeiros.
- [ ] `make test` roda as três suítes (Unit, Feature, Concurrency) e passa no CI.
- [ ] Cada teste novo foi visto falhando sem a correção (e o motivo anotado).
- [ ] Cobertura medida no CI com piso; `preventLazyLoading` fora de produção.
