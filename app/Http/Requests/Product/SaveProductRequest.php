<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseRequest;

class SaveProductRequest extends BaseRequest {

    public const MAX_SHELF_LIFE_DAYS = 36500;

    /**
     * A ordem das regras define qual mensagem aparece primeiro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:255',
            'unit_id' => 'required|integer|min:1',
            'sku' => 'nullable|string|max:40|regex:/^[\pL\pN._\-\s]+$/u',
            'is_atomic' => 'sometimes|boolean',
            'shelf_life_days' => 'nullable|integer|min:1|max:' . self::MAX_SHELF_LIFE_DAYS,
            'category_ids' => 'nullable|array',
            'components' => 'nullable|array',
            'components.*.id' => 'required|integer|min:1|distinct',
            'components.*.quantity_required' => 'required|numeric|gt:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'name.*' => 'messages.products.required_fields',
            'unit_id.*' => 'messages.products.required_fields',
            'sku.*' => 'messages.products.invalid_sku',
            'shelf_life_days.*' => 'messages.products.invalid_shelf_life',
            'category_ids.*' => 'messages.products.invalid_categories',
            'components.array' => 'messages.products.invalid_components',
            'components.*.id.distinct' => 'messages.products.duplicate_components',
            'components.*.id.*' => 'messages.products.invalid_components',
            'components.*.quantity_required.*' => 'messages.products.invalid_components',
        ];
    }
}
