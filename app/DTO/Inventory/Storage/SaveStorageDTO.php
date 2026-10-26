<?php

namespace App\DTO\Inventory\Storage;

use App\Http\Requests\Storage\SaveStorageRequest;
use Spatie\LaravelData\Data;

class SaveStorageDTO extends Data {

    /**
     * Descrição em branco é gravada como `null` (o middleware ConvertEmptyStringsToNull já cuida disso).
     */
    public function __construct(
        public string  $name,
        public ?string $description,
    ) {
    }

    /**
     * @param SaveStorageRequest $request
     * @return SaveStorageDTO
     */
    public static function fromRequest(SaveStorageRequest $request): SaveStorageDTO {
        return new self(
            name: $request->validated('name'),
            description: $request->validated('description'),
        );
    }
}
