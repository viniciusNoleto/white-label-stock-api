---
paths:
  - "app/Http/Requests/**"
  - "app/Data/**"
  - "app/Actions/**"
  - "app/Base/Helpers/**"
  - "lang/**"
---

# 03. Validação e normalização de dados

## Regras

### 3.1 FormRequest com mensagens por chave

- Toda FormRequest estende uma `BaseRequest` que:
  - autoriza (a autenticação fica no middleware);
  - converte a **primeira** falha de validação no 422 do envelope.
- `messages()` devolve **chaves** (`'name.*' => 'messages.units.required_fields'`). Regra sem mensagem própria cai em `messages.invalid_payload`.
- A ordem das regras e das chaves de `messages()` define qual mensagem aparece primeiro. `*` não atravessa pontos (`components.*.id.*`).
- Validação **cruzada** (um campo só se outro…) vai em `after()`.

### 3.2 DTO normaliza

- `fromRequest()` lê só `validated()`.
- Normaliza: trim, lowercase de e-mail, em branco → `null`, defaults, clamp de paginação (`per_page` 1..100), conversão de ids.
- Listagens: filtro inválido na query string é **ignorado** (não dá 422); paginação inválida volta ao padrão.

### 3.3 Ids

- Ids de rota ou payload passam por `parse_id()` (inteiro positivo ou `null`).
- Id inválido na rota → 404 (nunca 500 de SQL com `'abc'`).
- Id de referência no payload: validar **formato** (`integer|min:1`) na FormRequest e deixar a **existência** para a FK (23503 → 422 `invalid_reference`).
- Campo que aceita `null` com significado (desvincular) não pode aceitar lixo: `nullable|integer|min:1`. Nunca `nullable` sozinho com `parse_id` silencioso no DTO.

### 3.4 Decimais nunca passam por float

- Coluna `decimal(P,S)` ↔ string no PHP ↔ string no JSON (`"2.5000"`).
- Validação: `numeric|decimal:0,S|max:<maior valor da coluna>` (helper `decimal_rule()`):
  - recusa mais casas que a coluna (`0.00001` não vira `0`);
  - recusa notação científica;
  - recusa valor maior que a coluna (nada de 500 por overflow).
- Normalização: `to_decimal($v)` → `bcadd($string, '0', S)`; se chegou como número JSON, `sprintf('%.SF')`, que é exato dentro do limite validado.
- Contas com `bcmath` (`bcadd`, `bcsub`, `bccomp`, `bcmul`) sempre com a escala explícita **e vinda de uma constante só** (`DECIMAL_SCALE`, ao lado de `DECIMAL_MAX`). Nada de `private const SCALE = 4` em cada classe nem `bcadd($a, $b, 4)`: um teste de arquitetura procura `bc*(…, <número ≥ 1>)` em `app/`. Escala `0` é permitida, porque é truncar para inteiro de propósito.
- Incremento/decremento direto no banco passando a string: o banco calcula em `numeric`.
- Model com cast `decimal:S` para garantir a string na leitura.
- **Proibido:** `(float)`, `floatval`, `number_format((float)…)`, `round()` em quantidade/dinheiro. **Inclusive em helper de formatação de mensagem**: `format_decimal` também é bcmath (`bcadd($v, '0', 4)` e corta os zeros), porque `(float)"99999999999.9999"` vira `100000000000`.
- Validar as parcelas não basta: o **resultado** de uma conta (`atual + adicionar`) pode passar do máximo da coluna. Guarde o máximo numa constante (`DECIMAL_MAX`), reuse-a na regra de validação e compare o resultado com `bccomp` antes de gravar (→ 422).

### 3.5 Datas, "hoje" e fuso

- Timestamps gravados em **UTC** (`app.timezone = UTC`).
- "Hoje" de regra de negócio (vencido, data padrão, "vencendo em N dias", filtro por dia) vem do **fuso do negócio** (`BUSINESS_TIMEZONE`) por **um** helper:
  - `business_now()` / `business_today()`;
  - `business_day_start_utc($date)`: início do dia local convertido para UTC, para filtrar timestamps.
- Proibido `now()->toDateString()` / `today()` / `startOfDay()` para regra de calendário.
- Datas da API em `Y-m-d`, validadas com `date_format:Y-m-d` (payload) ou `parse_date()` (query string, inválido → ignorado).
- Intervalo de dias: início inclusivo, fim **exclusivo** no começo do dia seguinte, calculado na data local antes de converter.

### 3.6 Busca textual escapa curingas

Todo `LIKE`/`ILIKE` com texto do usuário passa por um helper `escape_like()` que escapa `\`, `%` e `_` (o escape padrão do Postgres é a barra). Sem isso, buscar `A_` casa com `AB` e buscar `%` devolve tudo. Teste com um caso que só passa com o escape.

### 3.7 Filtro por coluna `date` sem `whereDate`

Em coluna do tipo `date`, compare direto (`where('expires_at', $date)`). `whereDate` envolve a coluna num cast e o índice deixa de ser usado. `whereDate` só faz sentido em `timestamp`, e lá o certo costuma ser intervalo `[início do dia, início do dia seguinte)` no fuso do negócio (3.5).

## Por quê

- Mensagem por chave mantém o i18n e os testes estáveis.
- Normalizar no DTO deixa a Action com dados já canônicos e a regra de negócio limpa.
- Float perde precisão e transforma entrada inválida em valor válido errado, o pior tipo de bug, porque é silencioso.
- UTC para gravar e fuso do negócio para "hoje": sem isso, todo dia, a partir de certa hora, a regra discorda do relógio do usuário.

## Modelos

```php
// app/Base/Helpers/numbers.php
function decimal_rule(): string { return 'numeric|decimal:0,4|max:99999999999.9999'; }

function to_decimal(int|float|string $value, int $scale = 4): string {
    return is_float($value) ? sprintf('%.' . $scale . 'F', $value) : bcadd(trim((string)$value), '0', $scale);
}

// app/Base/Helpers/dates.php
function business_now(): Carbon   { return now()->setTimezone(config('app.business_timezone')); }
function business_today(): string { return business_now()->toDateString(); }
function business_day_start_utc(string $date): Carbon {
    return Carbon::parse($date, config('app.business_timezone'))->startOfDay()->setTimezone(config('app.timezone'));
}
```

```php
// FormRequest
'quantity'   => 'required|' . decimal_rule() . '|gt:0',
'storage_id' => 'nullable|integer|min:1',
```

### 3.8 Nada é descartado em silêncio

- **Todo elemento de lista tem regra.** `'category_ids' => 'nullable|array'` sozinho aceita `["abc", 0, null]`; com o DTO fazendo `->map(parse_id)->filter()`, o produto era gravado **sem categorias** e a resposta era 200. Regra: `'category_ids.*' => 'required|integer|min:1'` + mensagem para `category_ids.*` e `category_ids.*.*`, e o DTO só converte (`(int)$id`, `unique()`), nunca filtra.
- Corrigiu um campo? Procure o mesmo padrão nos outros. O projeto já corrigiu `storage_id` e esqueceu `category_ids`. Um `grep "->filter()"` nos DTOs acha os irmãos.
- Exceção que continua valendo: **filtro de listagem** inválido na query string é ignorado (3.2). Payload de escrita, nunca.

### 3.9 Regra entre campos que depende do que está gravado vai na Action

- Regra que só olha o payload vai no FormRequest: `produced_at` nunca no futuro → `'produced_at' => 'date_format:Y-m-d|before_or_equal:' . business_today()`, com mensagem própria (`produced_at.before_or_equal` **antes** de `produced_at.*` em `messages()`).
- Regra que cruza o payload com o registro gravado vai na Action, depois de montar os atributos finais. No PATCH parcial, `expires_at >= produced_at` tem de valer para **o resultado**: mandar só `expires_at` (comparado com o `produced_at` gravado) ou só `produced_at` (comparado com o `expires_at` gravado) também é violação.

```php
$produced_at = $attributes['produced_at'] ?? $item->produced_at->toDateString();
$expires_at = array_key_exists('expires_at', $attributes) ? $attributes['expires_at'] : $item->expires_at?->toDateString();

if ($expires_at !== null && $expires_at < $produced_at) {
    throw new ApiException('messages.items.expires_before_produced');
}
```

- "Futuro" é o **dia do negócio** (`business_today()`), não UTC. Teste às 23h30 locais (já é amanhã em UTC).

### 3.10 Campo redundante vira opcional, não obrigatório-e-conferido

Quando um campo é derivável de outro (quantidade = número de itens escolhidos), não obrigue o cliente a mandar os dois e devolver 422 quando divergem. Torne o derivável **opcional** (`required_without:items.*.item_ids|nullable|…`), derive no DTO (`to_decimal($item['quantity'] ?? count($item_ids))`) e, **se vier**, confira. Assim o contrato antigo continua funcionando (o cliente que já manda os dois não quebra) e o novo fica mais simples.

## Checklist

- [ ] Nenhum `(float)` em quantidade/valor (nem para formatar); validação com `decimal_rule()`; DTO com `to_decimal()`; resultado de conta comparado com `DECIMAL_MAX` antes de gravar.
- [ ] Buscas com `escape_like()`; filtro de coluna `date` sem `whereDate`.
- [ ] Testes de borda: mais casas que a coluna, número JSON, notação científica, acima do máximo.
- [ ] Nenhum `now()->toDateString()` em regra; testes com relógio perto da meia-noite local.
- [ ] Ids de referência com `integer|min:1`; existência via FK.
- [ ] Testes fixam `BUSINESS_TIMEZONE` (ex.: UTC) e os testes de fuso trocam explicitamente.
- [ ] Todo `campo.*` de lista com regra; nenhum `->filter()` em DTO de escrita.
- [ ] Datas com regra de domínio (não no futuro, fim ≥ início) checadas contra o estado final, inclusive no PATCH parcial.
- [ ] Campo derivável é opcional e conferido só quando vem.
- [ ] Escala bcmath só via `DECIMAL_SCALE` (teste de arquitetura).
