<?php

namespace App\Http\Requests\InventoryItem;

use App\Http\Requests\BaseRequest;

class BuildInventoryItemRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'quantity' => 'nullable|numeric|gt:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'quantity.*' => 'messages.inventory_items.build_quantity',
        ];
    }
}
