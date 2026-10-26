<?php

namespace App\Http\Requests\Storage;

use App\Http\Requests\BaseRequest;

class SaveStorageRequest extends BaseRequest {

    /**
     * @return array<string, mixed>
     */
    public function rules(): array {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array {
        return [
            'name.required' => 'messages.storages.name_required',
            'name.string' => 'messages.storages.name_required',
        ];
    }
}
