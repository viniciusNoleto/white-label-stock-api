---
paths:
  - "Makefile"
  - "scripts/**"
  - "docker-compose*.yml"
  - ".docker/**"
  - "config/logging.php"
  - ".env.example"
  - "routes/console.php"
---

# 10. Operação e deploy

## Regras

### 10.1 Ambiente em containers, interface por `make`

- PHP/Composer só nos containers. Todo comando do dia a dia tem um alvo no `Makefile`, com descrição (`## …`).
- Compose local e de produção separados. Produção não publica porta; entra na rede do proxy reverso.
- Onboarding: `cp .env.example .env && make install`. O banco de teste é criado pelo init do Postgres.
- `.env.example` documenta cada variável com comentário; `.env` nunca é versionado.

### 10.2 Saúde real

- `/up` (rota de health do Laravel) consulta as dependências críticas: `Event::listen(DiagnosingHealth::class, fn() => DB::select('SELECT 1'))`.
- Healthcheck em cada container:
  - web server: `wget /up`;
  - php-fpm: socket 9000;
  - banco: `pg_isready`.
- `depends_on: condition: service_healthy` na cadeia (banco → php-fpm → nginx).

### 10.3 Logs correlacionáveis

- Middleware global de request id:
  - reaproveita um `X-Request-Id` válido (regex de charset e tamanho) ou gera um UUID;
  - `Context::add('request_id', …)`, para entrar em todo log;
  - devolve no header.
- Produção loga em `stderr` (`LOG_STACK=stderr`), lido com `docker compose logs`.
- Eventos de segurança e de concorrência em `warning`, com contexto estruturado (`Log::warning('auth.refresh_token_reused', [...])`); nomes de evento estáveis, em `dominio.evento`.

### 10.4 Tarefas agendadas

- Agendamentos em `routes/console.php` (`Schedule::command(…)->daily…()->withoutOverlapping()->onOneServer()`).
- Um container `scheduler` com a mesma imagem rodando `php artisan schedule:work`, reiniciado no deploy.
- Teste que o agendamento existe com a expressão esperada.

### 10.5 Deploy reversível

Separe a **primeira instalação** (`make install-production`: sem dev, gera `APP_KEY` só se faltar, seed) do **deploy** (`make deploy`, ou `make deploy REF=<tag>`):

```
recusa se há mudança local não commitada        (o servidor só roda código versionado)
→ artisan down --retry=30                       (o código ANTIGO responde 503: ninguém escreve mais)
→ backup (pg_dump -Fc, retenção, cópia off-site) (consistente: a API está parada)
→ git pull --ff-only  |  git checkout REF        (o código só muda depois do backup)
→ compose up -d --build --remove-orphans         (não derruba o banco)
→ composer install --no-dev
→ optimize:clear → migrate (falhou: para, segue em manutenção)
→ config:cache + route:cache
→ restart php-fpm + scheduler                    (opcache sem revalidação: sem restart, bytecode velho)
→ artisan up
→ git tag deploy-AAAAMMDD-HHMMSS                 (toda versão que foi ao ar tem tag)
```

Por que esta ordem:
- `down` antes do backup: dump tirado com a API aceitando escrita perde o que entrar entre o dump e a migration;
- código novo só depois do `down` e do backup: antes, o php-fpm com `validate_timestamps=1` poderia servir metade do código novo com o banco antigo;
- produção roda com `opcache.validate_timestamps=0` (rápido), e o código vem de bind mount: o PHP **não percebe** que o arquivo mudou. O restart do php-fpm é o que troca o bytecode. Sem ele, "metade das rotas responde o comportamento antigo";
- a tag no fim: o README mandava fazer rollback "para a tag anterior", e nenhuma tag existia. Agora o deploy cria.

**Rollback:**
1. `git tag -l 'deploy-*'` lista o que foi ao ar;
2. só código: `make deploy REF=<tag anterior>`, seguro por causa do expand/contract (rule 04);
3. código e dados: `make restore file=<dump do deploy que falhou>` e depois `make deploy REF=<tag anterior>`;
4. voltar a seguir a branch: `make deploy`.

### 10.5b Backup que volta

- **Retenção:** guarde os últimos N dumps (`BACKUP_KEEP`), apague os mais velhos no próprio `make backup`.
- **Fora do disco:** `BACKUP_OFFSITE` (destino do `rsync`: outro disco ou `usuario@host:/caminho/`). Backup no mesmo disco do banco não sobrevive ao disco. Sem destino configurado, o comando avisa.
- **Restore testado:** `make backup-verify` restaura o dump mais recente num banco descartável, conta linhas de tabelas-chave e apaga o banco. Backup que nunca foi restaurado é hipótese.
- Backup diário no host (cron) além do backup de cada deploy.

### 10.6 Configuração

- Todo valor configurável passa por `config/*.php`; o código usa `config()`, nunca `env()` (necessário para `config:cache`).
- Parâmetros operacionais expostos por env com default sensato, por exemplo:
  - `DB_TRANSACTION_ATTEMPTS`;
  - `DB_LOCK_TIMEOUT`, `DB_STATEMENT_TIMEOUT`;
  - `BACKUP_KEEP`, `BACKUP_OFFSITE`;
  - `TRUSTED_PROXIES`;
  - `BUSINESS_TIMEZONE`.
- Checklist de produção no README: `LOG_LEVEL=info`, `APP_DEBUG=false`, error tracker (Sentry ou similar) antes do primeiro cliente pagante; se ainda não existe, está em "limitações".

### 10.7 Tag no remoto, restore seguro e logs que viram alerta

**Tag de rollback no remoto.** A tag `deploy-*` criada só no servidor morre com o disco. O deploy faz `git push $(DEPLOY_REMOTE) "$tag"` depois de criar a tag (o servidor precisa de deploy key com escrita). Se o push falha, o deploy **não** é desfeito (a API já está no ar), mas o aviso imprime o comando para enviar à mão. Rollback numa máquina nova: clonar, `make install-production`, restaurar o último dump, `git fetch --tags`, `make deploy REF=<tag>`.

**Restore com rede de segurança.** `make restore`:
1. confere que o arquivo existe e pede o nome do banco;
2. entra em manutenção;
3. **dump do estado atual** (`make backup BACKUP_PRUNE=0`) e mostra o caminho;
4. `pg_restore --clean`; se falhar, fica em manutenção e diz como voltar (`make restore file=<dump de segurança>`).

O `BACKUP_PRUNE=0` não é detalhe: com a retenção ligada, o backup de segurança podia **apagar justamente o dump antigo que ia ser restaurado** (ele era o mais velho da pasta).

**Logs que viram alerta.**
- `user_id` no `Context` (no middleware de autenticação), ao lado do `request_id`: todo log da requisição diz de quem ela veio.
- Formato JSON em produção sem código novo: `LOG_STACK=stderr` + `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` (o `config/logging.php` padrão já lê essa env).
- Alerta por webhook: o canal `slack` entra **sozinho** na pilha quando `LOG_SLACK_WEBHOOK_URL` está preenchido (sem URL, nada muda; com o canal fixo na pilha e sem URL, o Laravel quebraria ao criar o canal). Nível próprio (`LOG_SLACK_LEVEL=error`), separado do `LOG_LEVEL`: `info` no arquivo não pode virar uma mensagem por requisição no chat. Webhook "compatível com Slack" cobre Slack, Mattermost e Discord (`/slack` no fim).

  ```php
  'stack' => [
      'driver' => 'stack',
      'channels' => array_values(array_unique(array_filter([
          ...explode(',', (string)env('LOG_STACK', 'single')),
          env('LOG_SLACK_WEBHOOK_URL') ? 'slack' : null,
      ]))),
  ],
  ```
- Rotação dos logs dos containers no compose de produção, com âncora YAML:

  ```yaml
  x-logging: &default-logging
    driver: json-file
    options: { max-size: "10m", max-file: "5" }
  services:
    php-fpm:
      logging: *default-logging
  ```
- Monitor de uptime **externo** apontando para `/up`: é o único alerta que funciona com o servidor inteiro fora. Se a API não publica porta, exponha só esse caminho pelo proxy.
- Webhook não é error tracker (sem agrupamento nem controle de volume): diga isso nas limitações e diga qual é o próximo passo (Sentry).

### 10.8 Makefile é índice, script é lógica

- Alvo do Makefile com mais de uma ou duas linhas de shell vira script em `scripts/{operação}.sh`, e o alvo só chama: `deploy: ## … \n\t@bash scripts/deploy.sh $(REF)`.
- Os scripts começam com `source "$(dirname "$0")/lib.sh"`: `set -euo pipefail`, checagem das variáveis obrigatórias (`: "${DB_DATABASE:?…}"`) e funções comuns (`compose`, `artisan`, `pg_exec`, `fail`).
- A configuração vem do `.env` pelo Makefile (`include .env` + `export VAR…`), com os padrões no Makefile (`BACKUP_KEEP ?= 14`). O script não lê o `.env` sozinho (valor com espaço ou aspas quebraria um `source`).
- Shellcheck no CI (`shellcheck -x scripts/*.sh`) e num alvo local (`make lint-scripts`, imagem com tag fixa). Supressão só com o motivo no comentário (`# shellcheck disable=SC2012 # nomes gerados aqui…`).
- Arquivo gerado por um passo longo (dump) é gravado em `.partial` e renomeado no fim, com `trap` que limpa: arquivo pela metade nunca parece válido. Recurso temporário (banco descartável) é apagado num `trap EXIT`.
- Teste cada script de verdade no ambiente local, nos caminhos seguros (backup, verify, restore do próprio dump, a trava do deploy), e diga quais caminhos não foram exercitados.

## Checklist

- [ ] `/up` falha quando o banco cai; containers com healthcheck.
- [ ] Toda resposta com `X-Request-Id`; logs com `request_id`.
- [ ] Jobs agendados com container próprio e teste.
- [ ] Deploy na ordem manutenção → backup → código → migrate → restart do PHP → no ar → tag; rollback por `REF`.
- [ ] Backup com retenção, cópia fora do host e `backup-verify` rodado.
- [ ] Toda referência do runbook (tag, arquivo, comando) existe de verdade.
- [ ] Nenhum `env()` fora de `config/`.
- [ ] Tags de deploy enviadas ao remoto; restore faz dump do estado atual sem podar a pasta.
- [ ] `request_id` e `user_id` em todo log; JSON em produção; alerta de erro por webhook com nível próprio; rotação dos logs dos containers; monitor externo no `/up`.
- [ ] Makefile só com interface; lógica em `scripts/*.sh` com `set -euo pipefail` e shellcheck no CI.
