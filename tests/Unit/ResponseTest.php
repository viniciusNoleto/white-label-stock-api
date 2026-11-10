<?php

use App\Base\Traits\Response;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

it('answers a deadlock that survived the retries with 409, not 500', function () {
    $pdo = new PDOException('deadlock detected');
    (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, '40P01');

    $response = Response::returnError(new QueryException('pgsql', 'select 1', [], $pdo), 'messages.items.update_error');

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true))->toMatchArray(['success' => false, 'data' => null])
        ->and($response->getData(true)['message']['en-us'])->toBe('Another operation changed this data at the same time. Nothing was saved: try again.');
});

it('keeps Retry-After on 429', function () {
    $response = Response::returnError(new TooManyRequestsHttpException(42));

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('42');
});

it('answers maintenance (deploy) with 503 in the envelope', function () {
    $response = Response::returnError(new HttpException(503));

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getData(true)['message']['pt-br'])->toBe('O sistema está sendo atualizado. Tente de novo em instantes.');
});
