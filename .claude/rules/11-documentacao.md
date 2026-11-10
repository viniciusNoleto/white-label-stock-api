---
paths:
  - "README.md"
  - "AGENTS.md"
  - "CLAUDE.md"
  - "docs/**"
  - ".claude/**"
---

# 11. Documentação

## Regras

### 11.1 README que prova, não só descreve

Seções mínimas:
1. O que é, para quem, e o que significam os termos do nome do produto (ex.: "white label" = uma instância por marca, e **por quê**).
2. Decisões de stack não óbvias (ex.: por que o front é Angular e não o framework que o leitor esperaria) e como o cliente consome a API.
3. Quality gates (tabela ferramenta → comando) e badge de CI.
4. Formato de resposta e **tabela de status**.
5. **Todos** os endpoints (método, caminho, parâmetros, efeitos, erros). Endpoint novo entra no mesmo PR.
6. **Tabela "regra → como é garantida → teste que prova"** para cada promessa do domínio.
7. Autenticação: ciclo de tokens, rate limits e decisões de segurança com o raciocínio.
8. Permissões: o modelo atual, **por que** ele basta para o cliente-alvo, o que protege as operações destrutivas e qual é o caminho quando não bastar. "Sem policies por ação" numa linha de limitações não é decisão registrada; é uma frase.
9. Setup local e tabela de comandos.
10. Deploy: primeira instalação, atualização, **rollback** e política de migration.
11. Operação: logs, health, jobs.
12. **Limitações conhecidas**: decisões deliberadas, para não serem confundidas com esquecimento. Inclua as de produto (ex.: "sem preço", "sem alertas"), não só as técnicas.
14. "Try it": meia dúzia de comandos `curl` que fazem o fluxo principal, testados de verdade contra a API local. Quem avalia o projeto não vai montar um front para ver o que ele faz.
13. Licença coerente com o arquivo `LICENSE` e o `composer.json`. Na tabela de garantias, diga o **alcance** de cada prova. "A ordem global de locks evita deadlock" estava escrito e era falso para um caso (linha de produto); "a auditoria é garantida pela suíte" era um grep. Escreva o que o teste prova e o que ele não prova (ex.: "estática, heurística" × "dinâmica, comportamento").

### 11.2 Comentário verdadeiro ou nenhum

- Docblock de Action diz o que ela faz, a regra, os erros e as precondições ("deve rodar dentro de uma transação").
- Comentário que promete garantia ("evita deadlock") só existe se o mecanismo existir **e** for o motivo real. Ao mudar o código, revise o comentário no mesmo commit.
- Comentário explica **por quê**, não o quê. Remova os obsoletos (ex.: referência a cookie numa API que não usa cookie).
- Migration já executada só tem comentário corrigido se a correção não alterar o schema.

### 11.3 AGENTS.md / CLAUDE.md

Instruções para agentes e pessoas na raiz:
- stack;
- camadas com a tabela da rule 01;
- regras de domínio;
- envelope e status;
- sessão;
- migrations;
- qualidade;
- testes.

Regra nova do projeto entra lá no mesmo PR. Seja curto e normativo ("sempre", "nunca", com o motivo em meia frase).

### 11.4 Decisões registradas

Quando uma crítica é rejeitada ou adiada, registre a decisão e o raciocínio (README → "Known limitations", ou um ADR curto): o que foi decidido, por quê, o custo aceito e o gatilho para rever. Exemplos:
- "409 no cadastro mantido: sem e-mail transacional; mitigado por rate limit";
- "multi-tenant: uma instância por marca; caminho descrito";
- "dedupe de 10 s no refresh: um token roubado reapresentado dentro da janela não é detectado; aceito porque SSR + browser deslogariam o usuário várias vezes por dia";
- "`reason` da baixa opcional: o cliente web atual não manda; sem ele a baixa não entra no relatório de perdas".

### 11.5 Documento de estudo fica fora do repositório do produto

Mudança grande merece um documento com problema, porquê, como, funcionamento, interações e prova. Mas um documento de estudo pessoal **não** vai para o repositório: ele cria mais uma fonte de documentação que envelhece. Guarde em notas pessoais ou numa pasta ignorada pelo git. No repositório ficam:
- `README.md` para pessoas (contrato, garantias, operação, decisões);
- `AGENTS.md`/`CLAUDE.md` com as convenções normativas, curtas;
- o histórico de commits, com mensagens que explicam o porquê.

As rules do projeto (estas) ficam em `.claude/rules/`: normativas, com o porquê e um checklist, carregadas pelo Claude Code e ligadas pelo `AGENTS.md` para os outros agentes e para as pessoas. O `AGENTS.md` continua sendo o resumo curto; as rules são o detalhe.

### 11.6 Mecanismo difícil ganha documento próprio, para pessoas

O `AGENTS.md` diz **o que fazer** ("trave o produto com `lockedForWrite()`, nunca `lockForUpdate()`"). Isso não ensina ninguém a revisar um PR que muda lock. Para o mecanismo mais difícil do sistema, escreva um documento curto em `docs/` (ex.: `docs/concurrency.md`) com:

1. o problema em linguagem de negócio ("dois funcionários, um bolo");
2. o mecanismo e **por que ele está certo** (ex.: por que ler o reservado sem lock é correto em READ COMMITTED depois de travar os itens; o que o Postgres faz com o `WHERE` quando o lock é liberado);
3. cada regra com o cenário de deadlock que ela impede, a matriz de conflito dos locks e o teste que reproduz o cenário;
4. uma tabela "situação → SQLSTATE → o que a API faz → por quê";
5. um checklist para a próxima operação do mesmo tipo.

README e `AGENTS.md` apontam para ele, e o `AGENTS.md` diz qual fonte vale em caso de divergência (o comportamento do README, provado por teste). É também a melhor preparação para a pergunta "explique sem abrir o código".

### 11.7 Limitação de produto com o próximo passo

Lacuna conhecida que distorce uma métrica (motivo da baixa opcional, sem devolução) vai em "Known limitations" com: por que existe (contrato com o cliente atual, decisão de domínio pendente), o que mitiga hoje (o resumo mostra `reason: null`) e o que destrava (mudança coordenada com o web; tipo de movimentação de devolução ligado à linha do pacote).

## Checklist

- [ ] README atualizado no mesmo PR (endpoint, status, regra, comando).
- [ ] Cada garantia do README aponta para o teste que a prova.
- [ ] Nenhum comentário que contradiga o código.
- [ ] AGENTS.md com a regra nova.
- [ ] Decisões e limitações registradas com motivo (inclusive trade-offs de segurança e lacunas de produto).
- [ ] Nenhum documento de estudo pessoal no repositório; README, AGENTS.md, `docs/` e `.claude/rules/` são as fontes.
- [ ] Exemplos do README executados de verdade.
- [ ] O mecanismo mais difícil tem um documento para pessoas em `docs/`, ligado pelo README e pelo AGENTS.md.
- [ ] Limitações de produto com o que mitiga hoje e o que destrava.
