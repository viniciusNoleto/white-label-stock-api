---
paths:
  - "app/**"
  - "bootstrap/**"
  - "lang/**"
---

# 02. Respostas e erros

## Regra

1. Toda resposta, sucesso ou erro, usa um envelope único:
   ```json
   { "success": true, "message": { "pt-br": "…", "es-mx": "…", "en-us": "…" }, "data": {} }
   ```
2. `message` é sempre uma **chave** de tradução (`messages.units.created`), resolvida em todos os idiomas do cliente por um helper (`api_message($key, $replace)`). Mensagem nova entra em **todos** os arquivos de idioma.
3. Erro sempre com `data: null`.
4. Erro de regra de negócio: `throw new ApiException('messages.x.y', $status = 422, $replace)`.
5. Um único ponto converte exceção em resposta (`Response::returnError`), usado pelos controllers **e** pelo handler global de exceções.
6. Mapa de status fixo:

| Status | Quando | Origem |
|---|---|---|
| 400 | JSON malformado | middleware que valida o corpo |
| 401 | sem sessão / credenciais inválidas | middleware `auth` |
| 403 | sem permissão | middleware `admin` |
| 404 | não encontrado, **inclusive id inválido** e 405 | `ModelNotFoundException` |
| 409 | registro em uso (FK) **ou conflito de concorrência que sobrou dos retries**, lock do banco não obtido (55P03) **ou lock de cache que estourou a espera** | `ApiException` / `is_concurrency_failure` / `LockTimeoutException` |
| 422 | validação ou regra de negócio | FormRequest / `ApiException` |
| 429 | rate limit, **preservando `Retry-After`** | `ThrottleRequestsException` |
| 500 | inesperado; mensagem específica da operação (`…_error`) | fallback, com `report()` |
| 503 | manutenção (deploy), com `Retry-After` | `php artisan down` |

7. Toda resposta leva `X-Request-Id` (ver rule 10).
8. Regra de negócio **não** é logada (`dontReport(ApiException::class)`); 500 é logado **uma** vez (`dontReportDuplicates()`).
9. Rota inexistente é 404 **com ou sem token**: o `Route::fallback` fica **fora** do grupo `auth`. Dentro dele, um cliente sem sessão recebia 401 para uma URL errada e tentava renovar a sessão à toa.
10. Erro do banco que vem de um **valor** calculado (ex.: soma de duas quantidades válidas que passa do limite da coluna, SQLSTATE 22003) é prevenido na Action com uma checagem explícita e vira 422 com mensagem própria. Erro de banco não é mensagem para o usuário.

## Por quê

- Um envelope único permite ao cliente ter **um** tratador de resposta.
- Chave em vez de texto: a API serve vários idiomas sem o cliente traduzir, e o teste confere a mensagem exata.
- 409 para corrida: nada foi gravado; o cliente pode tentar de novo. 500 diria "bug" e esconderia o problema real nas métricas.
- 429/503 sem os headers originais fazem o cliente adivinhar quando tentar de novo.
- Log só do que é anomalia: se toda regra de negócio for logada, o erro de verdade se perde.

## Como

```php
// bootstrap/app.php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->dontReport(ApiException::class);
    $exceptions->dontReportDuplicates();
    $exceptions->render(fn(Throwable $e) => ResponseTrait::returnError($e));
})
```

```php
// Trait Response (trecho)
public static function returnError(Throwable $e, string $message = 'messages.server_error') {
    if ($e instanceof HttpResponseException) return $e->getResponse();          // validação já pronta
    if ($e instanceof ApiException)         return self::errorResponse($e->message_key, $e->status_code, $e->replace);
    if ($e instanceof ModelNotFoundException) return self::modelNotFoundResponse($e);
    if ($e instanceof HttpExceptionInterface && ($e->getStatusCode() < 500 || $e->getStatusCode() === 503)) {
        return self::httpExceptionResponse($e);   // 404/405→404, 429, 503 + headers
    }
    if (is_concurrency_failure($e))         return self::concurrencyFailureResponse($e); // 409 + Log::warning
    return self::internalServerErrorResponse($e, $message);                       // report() + 500
}
```

```php
// Exceção de negócio
class ApiException extends Exception {
    public function __construct(
        public readonly string $message_key,
        public readonly int    $status_code = 422,
        public readonly array  $replace = [],
    ) { parent::__construct($message_key, $status_code); }
}
```

- Em ambiente de depuração, um flag (`SHOW_ERROR_MESSAGE`) pode incluir `data.message_error` no 500. **Nunca em produção.**
- Middleware que força `Accept: application/json` em toda requisição, para que nenhum erro saia em HTML.
- `Route::fallback` dentro do grupo autenticado responde 404 no envelope.

## Contenção nunca é 500

Todo "não consegui o lock a tempo" é a mesma situação para o cliente, venha de onde vier: nada foi gravado, tente de novo.

| Origem | Exceção | Resposta |
|---|---|---|
| Postgres, deadlock/serialização depois dos retries | `QueryException` 40P01/40001 | 409 |
| Postgres, `lock_timeout` | `QueryException` 55P03 | 409 (sem retry: rule 05) |
| Cache, `Cache::lock()->block($s)` estourou | `Illuminate\Contracts\Cache\LockTimeoutException` | 409 |

```php
if (is_concurrency_failure($e) || $e instanceof LockTimeoutException) {
    return self::concurrencyFailureResponse($e);   // Log::warning com a classe e o SQLSTATE
}
```

O `Log::warning` leva a **classe** da exceção além do SQLSTATE: um 409 de cache e um de banco têm causas e correções diferentes, e o log precisa separar.

## Checklist

- [ ] Nenhuma resposta fora do envelope (inclusive 404 de rota, 429, 503).
- [ ] Mensagem nova nos N arquivos de idioma; um teste ou script confere que as chaves batem.
- [ ] Erros de regra com `ApiException` e status correto (409 em uso, 422 regra).
- [ ] 500 sempre com a mensagem específica da operação (`messages.x.create_error`).
- [ ] Testes conferem `message.<locale>` e `data: null` nos erros.
- [ ] Todo timeout de lock (banco **e** cache) responde 409 e é logado como `warning` com a classe da exceção; há teste segurando o lock e esperando o 409.
