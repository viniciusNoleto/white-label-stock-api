<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('builds the api message in every locale', function () {
    expect(api_message('messages.units.created'))->toBe([
        'pt-br' => 'Unidade criada com sucesso.',
        'es-mx' => 'Unidad creada con éxito.',
        'en-us' => 'Unit created successfully.',
    ]);
});

it('interpolates the api message', function () {
    expect(api_message('messages.items.created', ['count' => 8])['pt-br'])
        ->toBe('8 item(ns) gerado(s) com sucesso.');
});

it('parses only positive integer ids', function (mixed $value, ?int $expected) {
    expect(parse_id($value))->toBe($expected);
})->with([
    [5, 5],
    ['12', 12],
    [0, null],
    ['-1', null],
    ['abc', null],
    ['1.5', null],
    [null, null],
]);

it('formats decimals without trailing zeros, without going through float', function (string|int $value, string $expected) {
    expect(format_decimal($value))->toBe($expected);
})->with([
    ['16.0000', '16'],
    ['2.5000', '2.5'],
    ['0.0000', '0'],
    ['0.1250', '0.125'],
    ['-3.5000', '-3.5'],
    [7, '7'],
    // `(float)` + `number_format` devolvia "100000000000"
    ['99999999999.9999', '99999999999.9999'],
]);

it('hashes tokens with sha256', function () {
    $token = generate_opaque_token();

    expect($token)->toHaveLength(64)
        ->and(hash_token($token))->toBe(hash('sha256', $token))
        ->and(generate_opaque_token())->not->toBe($token);
});

it('detects whole decimals', function (string $value, bool $expected) {
    expect(is_whole_number($value))->toBe($expected);
})->with([
    ['3.0000', true],
    ['0', true],
    ['2.5000', false],
    ['0.0001', false],
]);

it('normalizes validated decimals to 4 places without going through float', function (int|float|string $value, string $expected) {
    expect(to_decimal($value))->toBe($expected);
})->with([
    ['2.5', '2.5000'],
    [' 3 ', '3.0000'],
    [3, '3.0000'],
    [0.1, '0.1000'],
    ['99999999999.9999', '99999999999.9999'],
    [99999999999.9999, '99999999999.9999'],
]);

it('takes "today" from the business timezone, not from UTC', function () {
    Carbon::setTestNow('2026-11-11 01:30:00');

    config(['app.business_timezone' => 'UTC']);
    expect(business_today())->toBe('2026-11-11');

    config(['app.business_timezone' => 'America/Sao_Paulo']);
    expect(business_today())->toBe('2026-11-10')
        ->and(business_day_start_utc('2026-11-10')->toDateTimeString())->toBe('2026-11-10 03:00:00');

    Carbon::setTestNow();
});

it('detects concurrency failures by SQLSTATE', function (string $sqlstate, bool $expected) {
    $pdo = new PDOException('x');
    (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlstate);

    expect(is_concurrency_failure(new QueryException('pgsql', 'select 1', [], $pdo)))->toBe($expected);
})->with([
    ['40P01', true],
    ['40001', true],
    ['55P03', true],
    ['23505', false],
]);

it('escapes LIKE wildcards so a search matches them literally', function () {
    expect(escape_like('50%_off\\x'))->toBe('50\\%\\_off\\\\x');
});
