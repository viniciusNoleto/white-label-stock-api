<?php

namespace App\Http\Requests\Item;

use App\Enum\Inventory\WriteOffReasonEnum;
use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class DeleteItemRequest extends BaseRequest {

    /**
     * `reason` é opcional (clientes antigos mandam o DELETE sem corpo): sem ele, a baixa fica sem motivo.
     *
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'reason' => ['nullable', Rule::enum(WriteOffReasonEnum::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'reason.*' => 'messages.items.invalid_write_off_reason',
        ];
    }
}
