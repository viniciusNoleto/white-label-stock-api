# Rules de projeto: API REST Laravel + PostgreSQL

Padrões do White Label Stock API, escritos de forma **genérica** para também servirem a outro projeto (os exemplos são deste). Cada arquivo traz a regra, **por que** ela existe, **como** aplicar (com modelo de código) e um **checklist** de revisão.

| # | Arquivo | Assunto |
|---|---|---|
| 01 | [01-arquitetura-em-camadas.md](01-arquitetura-em-camadas.md) | Controller → FormRequest → DTO → Action → Model; Query; Resource; Enum; helpers; scope de filtros compartilhado; contrato do framework trocado em `app/Base` |
| 02 | [02-respostas-e-erros.md](02-respostas-e-erros.md) | Envelope único, mensagens i18n por chave, mapa de status, `ApiException`, `returnError` |
| 03 | [03-validacao-e-dados.md](03-validacao-e-dados.md) | Mensagens de validação como chave, normalização no DTO, ids, decimais sem float (escala central), datas e fuso do negócio, nada descartado em silêncio, regra entre campos contra o estado gravado, campo derivável opcional |
| 04 | [04-banco-e-migrations.md](04-banco-e-migrations.md) | Banco como guardião (FK, unique, CHECK), tradução de SQLSTATE, expand/contract, índices de FK, snapshots |
| 05 | [05-concorrencia-e-transacoes.md](05-concorrencia-e-transacoes.md) | Transação na Action, ordem global de locks, retry por SQLSTATE, 409, advisory lock (por valor, sempre o último), dedupe com lock de cache, tudo-ou-nada em escritas repetidas |
| 06 | [06-auditoria.md](06-auditoria.md) | Log de movimentações na mesma transação, snapshot, registro imutável, garantia por teste, resumo agregado pelo snapshot |
| 07 | [07-autenticacao-e-seguranca.md](07-autenticacao-e-seguranca.md) | Tokens opacos com hash, rotação com detecção de reuso, rate limit, proxies confiáveis, tempo constante, limpeza |
| 08 | [08-testes.md](08-testes.md) | Pest contra banco real, `failTable`, cenários, concorrência com fork, testes de arquitetura, testes honestos |
| 09 | [09-qualidade-e-ci.md](09-qualidade-e-ci.md) | Pint com o estilo da casa, Larastan sem baseline, audit, `make ci`, GitHub Actions, testes dentro da imagem de produção, cobertura com piso, template de PR |
| 10 | [10-operacao-e-deploy.md](10-operacao-e-deploy.md) | Healthcheck real, request id + user id, logs JSON, alerta por webhook, rotação, scheduler, deploy com backup/manutenção, tag no remoto, restore com rede de segurança |
| 11 | [11-documentacao.md](11-documentacao.md) | README que prova garantias, comentários verdadeiros, decisões e limitações explícitas, AGENTS.md, documento do mecanismo difícil em `docs/` |

## Como estas rules são usadas

- **Neste projeto:** ficam em `.claude/rules/`. O Claude Code carrega este índice sempre e cada rule quando a tarefa toca os arquivos do `paths:` dela (frontmatter). O `AGENTS.md` é o resumo curto e aponta para cá; outros agentes e as pessoas abrem o arquivo do assunto.
- **Para pessoas:** guia de onboarding e checklist de code review (cada arquivo termina com um checklist).
- **Em outro projeto:** copie a pasta para `.claude/rules/` (ou `.cursor/rules/`, `docs/rules/`), ajuste os `paths:` e ponha no `AGENTS.md`/`CLAUDE.md` uma linha por rule. Adapte os nomes, não os princípios:
  - `{Domínio}`, `{Recurso}` e `messages.{recurso}.{ação}` são placeholders;
  - os helpers (`business_today`, `to_decimal`…) são sugestões; o que importa é existir **um** lugar para cada responsabilidade.
- **Ordem de adoção num projeto novo:** 01, 02, 03, 04 e 08 desde o primeiro commit; 05 e 06 quando houver estado disputado (saldo, estoque, agenda); 07, 09 e 10 antes do primeiro deploy; 11 sempre.

## Princípios que atravessam todas as regras

1. **Toda garantia anunciada tem mecanismo, teste e limite declarado.** Se o README, um comentário ou o nome de um teste promete algo ("evita deadlock", "nunca vencido", "concorrente"), existe código que garante e um teste que **falhou** sem ele (confira removendo a proteção). Se a verificação é parcial (uma heurística estática, por exemplo), o texto diz que é parcial.
2. **Defesa em profundidade.** Validação na borda, regra na Action, invariante no banco (FK, unique, CHECK) e convenção num teste de arquitetura.
3. **Falha honesta.** Erro de regra é 4xx com mensagem clara; corrida é 409 "tente de novo"; só o imprevisto é 500, e é logado com request id.
4. **Explícito em vez de mágico.** Dependências entram por parâmetro, o tempo de "hoje" vem de um helper, a ordem de locks é um scope nomeado.
5. **Reversível e verificável.** Migrations só expandem, o deploy faz backup com a API parada, toda versão publicada ganha tag, o backup é restaurado de verdade de tempos em tempos e o rollback é um comando.
6. **Fixo até alguém decidir mudar.** Versões de imagem, Actions e pacotes são fixas; a atualização chega por PR (Dependabot) e passa pelo CI.
7. **Nada pendura para sempre.** Timeout em lock e consulta no banco, em processos de teste e no job de CI.
8. **Nada some em silêncio.** Lixo no payload é 422, não "ignorado com 200"; erro do framework que deveria ser repetido é reconhecido pelo código, não pelo texto; lacuna de dado aparece como `null` no relatório; alerta chega a alguém.
9. **O que roda em produção é o que foi testado.** Mesma imagem no CI e no servidor, mesmas versões, mesmo banco.
