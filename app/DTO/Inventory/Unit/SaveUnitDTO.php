<?php

namespace App\DTO\Inventory\Unit;

use App\Http\Requests\Unit\SaveUnitRequest;
use Spatie\LaravelData\Data;

class SaveUnitDTO extends Data {

    public function __construct(
        public string $name,
        public string $abbreviation,
    ) {
    }

    /**
     * @param SaveUnitRequest $request
     * @return SaveUnitDTO
     */
    public static function fromRequest(SaveUnitRequest $request): SaveUnitDTO {
        return new self(
            name: $request->validated('name'),
            abbreviation: $request->validated('abbreviation'),
        );
    }
}
