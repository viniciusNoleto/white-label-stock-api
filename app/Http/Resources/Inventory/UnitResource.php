<?php

namespace App\Http\Resources\Inventory;

use App\Models\Inventory\Unit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Unit
 */
class UnitResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
