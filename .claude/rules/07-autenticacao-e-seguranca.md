---
paths:
  - "app/Actions/Auth/**"
  - "app/Actions/User/**"
  - "app/Http/Middleware/**"
  - "app/Providers/**"
  - "config/**"
  - "routes/**"
---

# 07. Autenticação e segurança

## Regras

### 7.1 Tokens opacos com hash

- Access e refresh tokens são bytes aleatórios (`bin2hex(random_bytes(32))`), não JWT. Só o **sha256** vai para o banco; o valor cru existe só na resposta de login/refresh.
- Access curto (ex.: 15 min) no header `Authorization: Bearer`. Refresh longo (ex.: 30 dias) só no corpo do refresh/logout.
- A API não lê nem grava cookies; quem guarda é o cliente (o web, no servidor dele).
- Logout fora do middleware de auth: com o access já expirado, ainda revoga o refresh.

### 7.2 Rotação com detecção de reuso

- Cada refresh revoga o token usado e emite outro na mesma **família** (`family_id`), com a linha travada (`lockForUpdate`).
- Reapresentar um token já revogado = cópia vazada → revoga a família inteira.
- Esse evento é logado como `warning` (`auth.refresh_token_reused`, com usuário e família).
- Rotação e emissão do access token novo na **mesma transação** (rule 05.8): falha na segunda escrita desfaz a rotação, e a nova tentativa do cliente não é confundida com roubo. Teste: `failTable('access_tokens')` → 500; restaura a tabela; o mesmo refresh token, depois da janela de dedupe, ainda funciona.
- Lock do dedupe que estoura a espera → 409 (rule 05.9), não 500.
- O **access token** também guarda o `family_id` do refresh emitido junto (coluna anulável, expand). Na detecção de reuso, os access tokens da família são apagados na mesma transação: o acesso acaba na hora, não "em até 15 min". Outras famílias (outros aparelhos) seguem.
- Dedupe de refreshes simultâneos (rule 05.6): documente **no README**, como trade-off com o motivo, que dentro da janela quem apresenta o mesmo token recebe o mesmo par e não dispara a detecção. É o custo consciente para SSR + browser não se deslogarem; mantenha a janela curta.

### 7.3 Rate limit nas rotas públicas

Limiters nomeados em `AppServiceProvider` e aplicados com `throttle:nome`:

| Rota | Limites | Chave |
|---|---|---|
| login | 5/min | e-mail + IP (força bruta numa conta) |
| | 20/min | IP (varredura) |
| cadastro público | 3/min e 10/h | IP |
| refresh | 10/min | hash do token (o SSR concentra usuários num IP) |
| | 300/min | IP (teto) |
| troca de senha | 5/min | hash do **bearer token** (quem roubou um token não testa senhas à vontade) |
| | 20/min | IP |

429 no envelope, com `Retry-After` (rule 02). O contador fica no cache compartilhado. Chave de limiter de rota autenticada: use o hash do token (`$request->bearerToken()`), não `$request->user()`. O callback do limiter não deve depender da ordem em que os middlewares resolvem o usuário; no projeto, `$request->user()` voltou `null` na primeira requisição de um teste e a chave mudava entre chamadas.

### 7.4 IP do cliente só de proxy confiável

- Nunca `trustProxies('*')`. Configure em `config/trustedproxy.php` (lido também com config em cache): `env('TRUSTED_PROXIES', 'PRIVATE_SUBNETS')`.
- A topologia esperada (proxy reverso e front SSR em rede privada repassando `X-Forwarded-For`) fica documentada.
- Teste que um `X-Forwarded-For` vindo de IP público é ignorado.

### 7.5 Não vazar existência de conta

- Mesma mensagem para "e-mail não existe" e "senha errada".
- **Mesmo custo**: exatamente um `Hash::check` em todo caminho. Quando não há conta, compare com um hash descartável gerado em runtime (`static $dummy ??= Hash::make(random)`), que acompanha o custo do bcrypt do ambiente.
- Se algum endpoint precisar revelar existência (ex.: 409 no cadastro sem e-mail transacional), documente a decisão e proteja com rate limit.

### 7.6 Higiene

- Tokens vencidos apagados por job agendado (`auth:prune-tokens` diário). Refresh revogado **ainda não vencido** fica, porque ele detecta o reuso.
- `composer audit` no CI; dependência vulnerável bloqueia o merge.
- `env()` só em `config/` (com config em cache, `env()` fora dele devolve `null`). Garantido por teste de arquitetura.
- Cadastro público cria **pedido** aprovado por admin, não usuário. Senha já com hash no pedido.
- Papéis mínimos: `is_admin` + middleware `admin` (403) para rotas administrativas; admin não pode ser excluído.

### 7.7 Ciclo de vida da senha

- **Uma** Action grava senha (`SetUserPasswordAction`): atualiza o hash e apaga **todos** os tokens do usuário, na mesma transação. Toda troca passa por ela, então "trocou a senha → todas as sessões caem" vale em todo caminho.
- Troca pelo próprio usuário: exige a senha atual (errada → 422, com rate limit), e devolve uma sessão nova para quem trocou (mesmo corpo do login) para ele não ser deslogado pela própria troca.
- Reset pelo admin, para usuário comum que esqueceu a senha (sem e-mail transacional não há "esqueci a senha"). Admin **não** redefine a senha de outro admin (seria tomar a conta).
- Recuperação do admin: comando de console (`user:reset-password {email}`) para quem opera o servidor, com a senha digitada duas vezes (`secret`) e nunca como argumento (ficaria no histórico do shell).

## Checklist

- [ ] Nenhum token cru persistido; hashes com índice único.
- [ ] Rotação travada; reuso revoga a família (refresh **e** access) e gera log.
- [ ] Troca/reset de senha encerram todas as sessões, por uma Action única.
- [ ] Todas as rotas públicas com `throttle` e testes de 429.
- [ ] Proxies confiáveis restritos e testados.
- [ ] Login com custo constante (teste contando `Hash::check`).
- [ ] Limpeza agendada de tokens; `composer audit` no CI.
- [ ] Rotação + access token numa transação; falha parcial não desloga o usuário (teste).
