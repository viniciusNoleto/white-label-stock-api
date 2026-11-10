<?php

namespace App\Data\Delivery\DeliveryPackage;

use App\Http\Requests\DeliveryPackage\SaveDeliveryPackageRequest;
use Spatie\LaravelData\Data;

class SaveDeliveryPackageDTO extends Data {

    /**
     * @param string|null $recipient para quem vai o pacote (cliente, pedido, mesa…); em branco → `null`
     * @param string|null $notes anotações em branco são gravadas como `null`
     * @param array<int, array{product_id: int, quantity: string, item_ids: int[]|null}> $items produtos sem repetição; quantidade com 4 casas (escala da coluna) para as contas com bcmath; `item_ids` = itens escolhidos um a um (`null` = FEFO); sem `quantity` no payload, vale `count(item_ids)`
     */
    public function __construct(
        public ?string $recipient,
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
        $recipient = $request->validated('recipient');

        return new self(
            recipient: is_string($recipient) && trim($recipient) !== '' ? trim($recipient) : null,
            notes: is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
            items: collect($request->validated('items'))
                ->map(function ($item) {
                    $item_ids = isset($item['item_ids']) ? array_map('intval', $item['item_ids']) : null;

                    return [
                        'product_id' => (int)$item['product_id'],
                        // Sem `quantity`, a escolha manual define quantos (a Action confere quando vêm os dois).
                        'quantity' => to_decimal($item['quantity'] ?? count($item_ids ?? [])),
                        'item_ids' => $item_ids,
                    ];
                })
                ->values()
                ->all(),
        );
    }
}
