<?php

namespace App\Http\Requests\DeliveryPackage;

use App\Http\Requests\BaseRequest;

class SaveDeliveryPackageRequest extends BaseRequest {

    public const NOTES_MAX_LENGTH = 10000;

    /**
     * A ordem das regras define qual mensagem aparece primeiro.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|integer|min:1|distinct',
            'items.*.quantity' => 'required|numeric|gt:0',
            'notes' => 'nullable|string|max:' . self::NOTES_MAX_LENGTH,
        ];
    }

    /**
     * `items.*.id.distinct` antes de `items.*.id.*`: a primeira chave que casa vence.
     *
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'items.*.id.distinct' => 'messages.delivery_packages.duplicate_items',
            'items.*.id.*' => 'messages.delivery_packages.invalid_items',
            'items.*.quantity.*' => 'messages.delivery_packages.invalid_items',
            'items.*' => 'messages.delivery_packages.invalid_items',
            'notes.*' => 'messages.delivery_packages.invalid_notes',
        ];
    }
}
