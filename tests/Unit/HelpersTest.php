<?php

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

it('formats decimals without trailing zeros', function (string|float $value, string $expected) {
    expect(format_decimal($value))->toBe($expected);
})->with([
    ['16.0000', '16'],
    ['2.5000', '2.5'],
    ['0.0000', '0'],
    [0.125, '0.125'],
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
