<?php

namespace App\Base\Traits;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Envelope único de todas as respostas da API:
 * `{ "success": bool, "message": { "pt-br", "es-mx", "en-us" }, "data": … }`.
 *
 * `message` é sempre uma chave de `lang/{locale}/messages.php`, resolvida nos três idiomas.
 * Erros sempre com `data: null` (exceto 500 com `SHOW_ERROR_MESSAGE=true`, para depuração).
 */
trait Response {

    /**
     * @param mixed $data
     * @param string $message
     * @param array<string, string|int|float> $replace
     * @param int $status_code
     * @return JsonResponse
     */
    public static function successResponse(
        mixed  $data = null,
        string $message = 'messages.success',
        array  $replace = [],
        int    $status_code = 200
    ): JsonResponse {
        return self::jsonResponse(
            response: self::defineResponseBase(success: true, message: $message, replace: $replace, data: $data),
            status_code: $status_code
        );
    }

    /**
     * @param string $message
     * @param int $status_code
     * @param array<string, string|int|float> $replace
     * @return JsonResponse
     */
    public static function errorResponse(
        string $message,
        int    $status_code = 400,
        array  $replace = []
    ): JsonResponse {
        return self::jsonResponse(
            response: self::defineResponseBase(success: false, message: $message, replace: $replace),
            status_code: $status_code
        );
    }

    /**
     * @param Throwable $exception
     * @param string $message mensagem usada quando o erro é inesperado (500)
     * @return HttpResponse|JsonResponse
     */
    public static function returnError(
        Throwable $exception,
        string    $message = 'messages.server_error'
    ): HttpResponse|JsonResponse {
        if ($exception instanceof HttpResponseException) {
            return $exception->getResponse();
        }

        if ($exception instanceof ApiException) {
            return self::errorResponse($exception->message_key, $exception->status_code, $exception->replace);
        }

        if ($exception instanceof ModelNotFoundException) {
            return self::modelNotFoundResponse($exception);
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return self::httpExceptionResponse($exception);
        }

        return self::internalServerErrorResponse($exception, $message);
    }

    /**
     * @param Throwable $exception
     * @param string $message
     * @return JsonResponse
     */
    public static function internalServerErrorResponse(
        Throwable $exception,
        string    $message = 'messages.server_error'
    ): JsonResponse {
        report($exception);

        $response = self::defineResponseBase(success: false, message: $message);

        if (config('logging.show_error_message')) {
            $response['data'] = ['message_error' => $exception->getMessage()];
        }

        return self::jsonResponse(response: $response, status_code: 500);
    }

    /**
     * O `getMessage()` da exceção é a chave da mensagem (ex.: `messages.units.not_found`).
     *
     * @param ModelNotFoundException $exception
     * @return JsonResponse
     */
    public static function modelNotFoundResponse(ModelNotFoundException $exception): JsonResponse {
        return self::errorResponse(
            message: is_message_key($exception->getMessage()) ? $exception->getMessage() : 'messages.not_found',
            status_code: 404
        );
    }

    /**
     * Rota inexistente, método não permitido, JSON malformado…
     *
     * @param HttpExceptionInterface $exception
     * @return JsonResponse
     */
    public static function httpExceptionResponse(HttpExceptionInterface $exception): JsonResponse {
        $status_code = $exception->getStatusCode();

        $default_message = in_array($status_code, [404, 405]) ? 'messages.not_found' : 'messages.invalid_payload';

        return self::errorResponse(
            message: is_message_key($exception->getMessage()) ? $exception->getMessage() : $default_message,
            status_code: $status_code === 405 ? 404 : $status_code
        );
    }

    /**
     * As FormRequests usam chaves de mensagem em `messages()`: a primeira falha vira a mensagem da resposta.
     *
     * @param Validator $validator
     * @return never
     */
    public static function failedValidationResponse(Validator $validator): never {
        $first_error = $validator->errors()->first();

        throw new HttpResponseException(
            self::errorResponse(
                message: is_message_key($first_error) ? $first_error : 'messages.invalid_payload',
                status_code: 422
            )
        );
    }

    /**
     * @param string $message
     * @param int $status_code
     * @return never
     */
    public static function notAuthorizeExceptionResponse(
        string $message = 'messages.unauthorized',
        int    $status_code = 401
    ): never {
        throw new HttpResponseException(self::errorResponse($message, $status_code));
    }

    /**
     * @param bool $success
     * @param string $message
     * @param array<string, string|int|float> $replace
     * @param mixed $data
     * @return array<string, mixed>
     */
    private static function defineResponseBase(
        bool   $success,
        string $message,
        array  $replace = [],
        mixed  $data = null
    ): array {
        return [
            'success' => $success,
            'message' => api_message($message, $replace),
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @param int $status_code
     * @return JsonResponse
     */
    private static function jsonResponse(array $response, int $status_code): JsonResponse {
        return response()->json($response, $status_code, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
