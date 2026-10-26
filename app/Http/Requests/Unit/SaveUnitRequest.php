<?php

namespace App\Http\Requests\Unit;

use App\Http\Requests\BaseRequest;

class SaveUnitRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:100',
            'abbreviation' => 'required|string|max:20',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            '*.required' => 'messages.units.required_fields',
            '*.string' => 'messages.units.required_fields',
        ];
    }
}
