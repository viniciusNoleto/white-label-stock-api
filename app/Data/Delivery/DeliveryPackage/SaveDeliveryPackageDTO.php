<?php

namespace App\Data\Delivery\DeliveryPackage;

use App\Http\Requests\DeliveryPackage\SaveDeliveryPackageRequest;
use Spatie\LaravelData\Data;

class SaveDeliveryPackageDTO extends Data {

    /**
     * @param string|null $notes anotações em branco são gravadas como `null`
     * @param array<int, array{product_id: int, quantity: string}> $items produtos sem repetição; quantidade com 4 casas (escala da coluna) para as contas com bcmath
     */
    public function __construct(
        public ?string $notes,
        public array   $items,
    ) {
    }

    /**
     * @param SaveDeliveryPackageRequest $request
     * @return SaveDeliveryPackageDTO
     */
    public static function fromRequest(SaveDeliveryPackageRequest $request): SaveDeliveryPackageDTO {
        $notes = $request->validated('notes');

        return new self(
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            items: collect($request->validated('items'))
                ->map(fn($item) => [
                    'product_id' => (int)$item['product_id'],
                    'quantity' => number_format((float)$item['quantity'], 4, '.', ''),
                ])
                ->values()
                ->all(),
        );
    }
}
