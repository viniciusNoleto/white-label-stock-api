---
paths:
  - ".github/**"
  - "pint.json"
  - "phpstan.neon"
  - "composer.json"
  - ".docker/**"
---

# 09. Qualidade e CI

## Regras

1. **Quatro gates obrigatórios**, iguais local e no CI:

   | Gate | Ferramenta | Comando |
   |---|---|---|
   | Estilo | Pint com `pint.json` | `make lint` / `composer lint` |
   | Análise estática | Larastan (PHPStan) | `make analyse` / `composer analyse` |
   | Vulnerabilidades | `composer audit --locked` | `make audit` |
   | Testes | Pest (Unit, Feature, Concurrency) | `make test` / `composer test` |

   `make ci` / `composer ci` roda tudo. Nada entra na branch principal sem CI verde.

2. **Pint descreve o estilo da casa.** Se o projeto já tem um estilo consistente, o `pint.json` parte do preset e **desliga ou ajusta** as regras que conflitam (chave na mesma linha, alinhamento de tipos, etc.), em vez de reformatar o código todo. Depois o Pint corrige o resto e passa a ser obrigatório.

3. **Larastan sem baseline.** Comece num nível alcançável (5 é bom para Laravel) e **corrija** os erros:
   - generics nas relações: `@return BelongsTo<Model, $this>`;
   - `@mixin Model` nos Resources;
   - `@property` para colunas com cast e `@property-read` para colunas calculadas;
   - `@var` pontual onde a inferência falha, com comentário.

   `ignoreErrors` só com identificador, caminho e comentário explicando (ex.: `catch.neverThrown` em Actions, porque `delete()` lança `QueryException` sem declarar). Suba de nível quando estiver limpo.

4. **Dependências:**
   - `composer.lock` versionado;
   - atualização de segurança imediata, dentro das restrições;
   - `require.php` igual à versão realmente testada (Dockerfile e CI).
   - ** Tudo fixo:** imagens Docker por tag exata (`php:8.5.12-fpm`, `postgres:16.14-alpine`, `composer:2.10.3`, não `php:8.5-fpm`/`nginx:alpine`), GitHub Actions por **SHA do commit** com a tag no comentário (`actions/checkout@<sha> # v4.4.0`: tag pode ser movida, commit não), pacotes pelo lock.
   - ** Quem atualiza é um robô, por PR:** `.github/dependabot.yml` com `composer`, `github-actions`, `docker` e `docker-compose`, semanal, dev-dependencies agrupadas. A versão muda num PR que passa pelo CI, nunca no próximo `docker build`.
   - Antes de fixar uma tag, confira que ela existe (`docker manifest inspect imagem:tag`); para o SHA de uma Action, `git ls-remote --tags https://github.com/dono/repo` (tag anotada: use a linha `^{}`).

5. **Pipeline (GitHub Actions)**, dois jobs:
   - `quality`: lint, analyse e audit;
   - `tests`: serviço Postgres na versão de produção, extensões necessárias (inclusive `pcntl`/`posix` para concorrência) e `.env` a partir do `.env.example`. Substituído pelo 9.8: o job de testes agora roda **dentro da imagem de produção**, e não num PHP do `setup-php`.

   Variáveis `DB_*` do job sobrepõem o `.env`. Cache de `vendor` por hash do lock. `concurrency` cancela runs antigos da mesma ref. `timeout-minutes` em cada job (ex.: 10 e 15; o padrão do GitHub é 6 h) e `permissions: contents: read` no workflow. Script do composer não pode ter o nome de um comando nativo (`audit` é comando do Composer): use `audit-deps` e inclua no `composer ci`, para `make ci` e `composer ci` rodarem a mesma coisa.

6. **Commits pequenos com o porquê.** Uma mudança de comportamento por commit; a mensagem diz o problema e a decisão, não "refat". Mudanças grandes (troca de arquitetura) em PRs incrementais.
7. ** O que o README promete precisa estar no histórico.** Trabalho pronto que fica no working tree não existe para quem abre o repositório: badge de CI que nunca rodou, testes e migrations que "o README diz que existem". Commit e push no fim de cada bloco de trabalho, e o CI verde pelo menos uma vez no remoto.

## Modelos

```json
// pint.json (exemplo: preset laravel + estilo de chave na mesma linha)
{
    "preset": "laravel",
    "rules": {
        "braces_position": { "classes_opening_brace": "same_line", "functions_opening_brace": "same_line" },
        "class_attributes_separation": false,
        "type_declaration_spaces": false,
        "single_line_empty_body": false,
        "cast_spaces": { "space": "none" },
        "function_declaration": { "closure_fn_spacing": "none" },
        "concat_space": { "spacing": "one" },
        "no_superfluous_phpdoc_tags": false,
        "phpdoc_separation": false
    }
}
```

```neon
# phpstan.neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    level: 5
    paths: [app, config, database, routes]
    tmpDir: storage/framework/cache/phpstan
    ignoreErrors:
        # delete()/save() lançam QueryException sem declarar: os catch que traduzem 23503/23505 são intencionais.
        - identifier: catch.neverThrown
          path: app/Actions/*
```

```json
// composer.json → scripts
"test": ["@php artisan config:clear --ansi @no_additional_args", "@php vendor/bin/pest"],
"lint": ["@php vendor/bin/pint --test"],
"analyse": ["@php vendor/bin/phpstan analyse --memory-limit=1G"],
"ci": ["@lint", "@analyse", "@test"]
```

### 9.8 O CI testa a imagem que vai para produção

Testar num PHP instalado pelo `setup-php` (`php-version: '8.5'`, que flutua no patch) enquanto produção roda `php:8.5.12-fpm` deixa um buraco: o PR do Dependabot que sobe a imagem passa verde sem ninguém ter rodado nada nela. Regra:

- o job de testes **constrói o Dockerfile de produção** e roda a suíte **dentro** da imagem, com o código montado como em produção e o Postgres do `services` pela rede do host:

  ```yaml
  env:
    IN_PHP_IMAGE: >-
      docker run --rm --network host
      -v ${{ github.workspace }}:/var/www/html
      -v ${{ github.workspace }}/.docker/php/php.ini:/usr/local/etc/php/php.ini
      -e DB_CONNECTION -e DB_HOST -e DB_PORT -e DB_DATABASE -e DB_USERNAME -e DB_PASSWORD
      app-php:ci
  steps:
    - run: docker build --build-arg UID="$(id -u)" --build-arg GID="$(id -g)" --build-arg INSTALL_PCOV=1 -t app-php:ci .docker/php
    - run: docker build -t app-nginx:ci .docker/nginx
    - run: $IN_PHP_IMAGE composer install --no-interaction --prefer-dist
    - run: $IN_PHP_IMAGE composer test-coverage
  ```
- `UID`/`GID` do runner como build args: o usuário da imagem escreve `vendor/` e `storage/` no workspace montado;
- ferramenta só de teste (pcov) entra por **build arg** desligado por padrão (`ARG INSTALL_PCOV=0`), ligado no CI e no compose local; a imagem de produção não leva;
- o cache do `vendor` inclui o hash do Dockerfile na chave (o vendor foi instalado pelo PHP da imagem);
- sem Action nova de terceiro para isso: `docker build`/`docker run` já existem no runner, e cada Action nova é mais um SHA para fixar e auditar;
- lint, análise estática e audit podem ficar no `setup-php` (não dependem do patch), dito num comentário no workflow;
- valide o workflow antes do push: `docker run --rm -v "$PWD":/w -w /w rhysd/actionlint:<versão> .github/workflows/ci.yml`, e simule o job localmente (build da imagem + Postgres descartável + `docker run --network host … pest`).

### 9.9 Cobertura com piso

Script `composer test-coverage` (`pest --coverage --min=N`) e alvo `make test-coverage`. N um pouco abaixo do medido (97% → piso 95%). Sobe quando o real subir.

### 9.10 Fluxo de contribuição escrito

- `.github/pull_request_template.md` com: o que muda, por quê, como foi testado (`make ci`, teste que falha sem a mudança, teste de concorrência quando mexe em lock), contrato com o cliente, mensagens nos três idiomas, migration só expande, movimentação registrada, docs atualizadas.
- Seção "Contributing" no README: `main` protegida, um assunto por PR, commits com o porquê (`fix(items): reject a production date in the future`).
- Uma rodada grande de mudanças é entregue como **sequência de commits pequenos**, cada um com teste e mensagem, planejados antes de começar a commitar (um assunto por commit, `git add -p` quando um arquivo mistura assuntos).

## Checklist

- [ ] `make ci` verde localmente antes do push.
- [ ] Nenhum erro novo silenciado no Larastan sem comentário.
- [ ] `composer audit` limpo.
- [ ] Nenhuma imagem/Action sem versão fixa; Dependabot configurado; jobs com `timeout-minutes`.
- [ ] Nada que o README cita está fora do git.
- [ ] Commit com mensagem que explica o porquê.
- [ ] Testes do CI rodam dentro da imagem de produção; o build do nginx também roda.
- [ ] Cobertura com piso; workflow validado com actionlint.
- [ ] Template de PR e seção de contribuição no README.
