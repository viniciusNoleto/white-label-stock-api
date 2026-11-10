<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Painel (`DashboardStatsQuery`, possivelmente vindo do cache). Contagens são `number`; quantidades,
 * como no resto da API, `string` com 4 casas e sempre por produto (com a unidade).
 * `generated_at` diz de quando são os números (o cliente mostra "atualizado às…").
 *
 * @property array<string, mixed> $resource
 */
class DashboardResource extends JsonResource {

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array {
        $data = $this->resource;

        return [
            'generated_at' => $data['generated_at'],
            'period' => $data['period'],
            'stock' => $data['stock'],
            'deliveries' => $data['deliveries'],
            'losses' => $data['losses'],
            'activity' => $data['activity'],
            'top_delivered' => $data['top_delivered'],
            'restock' => $data['restock'],
            'expiration_alerts' => $data['expiration_alerts'],
        ];
    }
}
