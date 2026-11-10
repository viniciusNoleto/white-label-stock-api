<?php

namespace App\Http\Requests\DeliveryPackage;

use App\Http\Requests\BaseRequest;

class SaveDeliveryPackageRequest extends BaseRequest {

    public const NOTES_MAX_LENGTH = 10000;

    /**
     * A ordem das regras define qual mensagem aparece primeiro. `item_ids` (opcional, só atômicos):
     * os itens escolhidos um a um no lugar do FEFO; a quantidade precisa bater com eles.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|min:1|distinct',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.item_ids' => 'nullable|array|min:1',
            'items.*.item_ids.*' => 'required|integer|min:1|distinct',
            'notes' => 'nullable|string|max:' . self::NOTES_MAX_LENGTH,
        ];
    }

    /**
     * `items.*.product_id.distinct` antes de `items.*.product_id.*`: a primeira chave que casa vence.
     *
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'items.*.product_id.distinct' => 'messages.delivery_packages.duplicate_items',
            'items.*.product_id.*' => 'messages.delivery_packages.invalid_items',
            'items.*.quantity.*' => 'messages.delivery_packages.invalid_items',
            'items.*.item_ids.*.*' => 'messages.delivery_packages.invalid_selection_ids',
            'items.*.item_ids.*' => 'messages.delivery_packages.invalid_selection_ids',
            'items.*' => 'messages.delivery_packages.invalid_items',
            'notes.*' => 'messages.delivery_packages.invalid_notes',
        ];
    }
}
