<?php

namespace App\Http\Requests\InventoryItem;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Validator;

class UpdateInventoryItemRequest extends BaseRequest {

    private const QUANTITY_FIELDS = ['quantity', 'quantity_add', 'quantity_subtract'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'quantity' => 'nullable|numeric|min:0',
            'quantity_add' => 'nullable|numeric|gt:0',
            'quantity_subtract' => 'nullable|numeric|gt:0',
            'name' => 'sometimes|required|string|max:255',
            'unit_id' => 'sometimes|required|integer|min:1',
            'category_ids' => 'sometimes|present|array',
            'storage_id' => 'nullable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'quantity.*' => 'messages.inventory_items.invalid_quantity',
            'quantity_add.*' => 'messages.inventory_items.invalid_quantity',
            'quantity_subtract.*' => 'messages.inventory_items.invalid_quantity',
            'name.*' => 'messages.inventory_items.name_required',
            'unit_id.*' => 'messages.inventory_items.unit_required',
            'category_ids.*' => 'messages.inventory_items.invalid_categories',
        ];
    }

    /**
     * Payload sem nenhum campo atualizável (`storage_id: null` conta: desvincula o armazenamento;
     * `category_ids: []` conta: remove todas as categorias)
     * ou com mais de um modo de quantidade (definir, somar e subtrair são exclusivos).
     *
     * @return array<int, callable>
     */
    public function after(): array {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $quantity_fields = collect(self::QUANTITY_FIELDS)->filter(fn($field) => $this->input($field) !== null)->count();

                if ($quantity_fields > 1) {
                    $validator->errors()->add('payload', 'messages.inventory_items.single_quantity_mode');
                } elseif (!$quantity_fields && !$this->hasAny(['name', 'unit_id', 'category_ids', 'storage_id'])) {
                    $validator->errors()->add('payload', 'messages.inventory_items.no_fields');
                }
            },
        ];
    }
}
