<?php

namespace App\Http\Requests\Item;

use App\Http\Requests\BaseRequest;

class CreateItemsRequest extends BaseRequest {

    /**
     * Sem `code`: o código do item é sempre gerado pela API (`{SKU}-0001`…).
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'product_id' => 'required|integer|min:1',
            'quantity' => 'nullable|numeric|gt:0',
            'produced_at' => 'nullable|date_format:Y-m-d',
            'storage_id' => 'nullable',
            'consume_components' => 'nullable|boolean',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'product_id.*' => 'messages.items.product_required',
            'quantity.*' => 'messages.items.invalid_quantity',
            'produced_at.*' => 'messages.items.invalid_date',
        ];
    }
}
