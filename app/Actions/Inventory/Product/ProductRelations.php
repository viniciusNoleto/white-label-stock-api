<?php

namespace App\Actions\Inventory\Product;

/**
 * Relações carregadas em toda resposta de produto (`ProductResource`).
 */
final class ProductRelations {

    public const ALL = [
        'unit',
        'categories',
        'components',
    ];
}
