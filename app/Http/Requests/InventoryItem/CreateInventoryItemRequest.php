<?php

namespace App\Http\Requests\InventoryItem;

use App\Http\Requests\BaseRequest;

class CreateInventoryItemRequest extends BaseRequest {

    /**
     * A ordem das regras define qual mensagem aparece primeiro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:255',
            'unit_id' => 'required|integer|min:1',
            'quantity' => 'nullable|numeric|min:0',
            'storage_id' => 'nullable',
            'category_ids' => 'nullable|array',
            'components' => 'nullable|array',
            'components.*.id' => 'required|integer|min:1',
            'components.*.quantity_required' => 'required|numeric|gt:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'name.*' => 'messages.inventory_items.required_fields',
            'unit_id.*' => 'messages.inventory_items.required_fields',
            'quantity.*' => 'messages.inventory_items.invalid_quantity',
            'components.array' => 'messages.inventory_items.invalid_components',
            'components.*.id.*' => 'messages.inventory_items.invalid_components',
            'components.*.quantity_required.*' => 'messages.inventory_items.invalid_components',
        ];
    }
}
