<?php

namespace App\Http\Requests\ItemCategory;

use App\Http\Requests\BaseRequest;

class SaveItemCategoryRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:100',
            'color_hex' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            '*.required' => 'messages.item_categories.required_fields',
            '*.string' => 'messages.item_categories.required_fields',
            'color_hex.regex' => 'messages.item_categories.invalid_color',
        ];
    }
}
