<?php

namespace App\Data\Inventory\ItemCategory;

use App\Http\Requests\ItemCategory\SaveItemCategoryRequest;
use Spatie\LaravelData\Data;

class SaveItemCategoryDTO extends Data {

    public function __construct(
        public string $name,
        public string $color_hex,
    ) {
    }

    /**
     * @param SaveItemCategoryRequest $request
     * @return SaveItemCategoryDTO
     */
    public static function fromRequest(SaveItemCategoryRequest $request): SaveItemCategoryDTO {
        return new self(
            name: $request->validated('name'),
            color_hex: $request->validated('color_hex'),
        );
    }
}
