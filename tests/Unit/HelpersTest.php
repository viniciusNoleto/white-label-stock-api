<?php

it('builds the api message in every locale', function () {
    expect(api_message('messages.units.created'))->toBe([
        'pt-br' => 'Unidade criada com sucesso.',
        'es-mx' => 'Unidad creada con éxito.',
        'en-us' => 'Unit created successfully.',
    ]);
});

it('interpolates the api message', function () {
    expect(api_message('messages.inventory_items.built', ['quantity' => 2])['pt-br'])
        ->toBe('2 unidade(s) construída(s) com sucesso.');
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
