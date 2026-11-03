<?php

namespace App\Data\Delivery\DeliveryPackage;

use App\Enum\Delivery\DeliveryPackageStatusEnum;
use Illuminate\Http\Request;
use Spatie\LaravelData\Data;

class ListDeliveryPackagesDTO extends Data {

    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public ?DeliveryPackageStatusEnum $status,
        public int                        $page,
        public int                        $per_page,
    ) {
    }

    /**
     * `status` desconhecido é ignorado (lista todos); `page` inválido vira 1;
     * `per_page` inválido vira 15 e é limitado a 1..100.
     *
     * @param Request $request
     * @return ListDeliveryPackagesDTO
     */
    public static function fromRequest(Request $request): ListDeliveryPackagesDTO {
        $status = $request->query('status');
        $page = $request->query('page');
        $per_page = $request->query('per_page');

        return new self(
            status: is_string($status) ? DeliveryPackageStatusEnum::tryFrom(strtolower(trim($status))) : null,
            page: max(1, is_numeric($page) ? (int)$page : 1),
            per_page: min(self::MAX_PER_PAGE, max(1, is_numeric($per_page) && (int)$per_page !== 0 ? (int)$per_page : self::DEFAULT_PER_PAGE)),
        );
    }
}
