<?php

namespace App\Queries\Inventory;

use App\Data\Inventory\StockMovement\ListStockMovementsDTO;
use App\Models\Inventory\StockMovement;
use Illuminate\Database\Eloquent\Collection;

final class SummarizeStockMovementsQuery {

    /**
     * Totais das movimentações por produto, tipo e motivo, com os mesmos filtros da listagem: responde
     * "quanto perdi por vencimento em novembro, em kg" com uma linha por produto, em vez de o cliente
     * somar a listagem página a página.
     *
     * Agrupa pelo que foi **copiado** na movimentação (`product_name`, `unit_abbreviation`): o produto
     * renomeado ou excluído continua aparecendo como era. `quantity` é a soma com sinal (perda sai
     * negativa) e não é limitada a `decimal(15, 4)`; `movements_count` conta as linhas (útil na
     * transferência, que tem quantidade zero). Baixa sem motivo aparece com `reason: null`: o resumo
     * mostra quanto da perda ainda não foi classificado.
     *
     * Sem paginação: o número de linhas é limitado por produtos × tipos × motivos.
     *
     * @param ListStockMovementsDTO $dto paginação ignorada
     * @return Collection<int, StockMovement>
     */
    public function get(ListStockMovementsDTO $dto): Collection {
        return StockMovement::query()
            ->filteredBy($dto)
            ->select(['product_id', 'product_name', 'unit_abbreviation', 'type', 'reason'])
            ->selectRaw('SUM(quantity)::numeric(30, 4)::text AS total_quantity')
            ->selectRaw('COUNT(*) AS movements_count')
            ->groupBy(['product_id', 'product_name', 'unit_abbreviation', 'type', 'reason'])
            ->orderBy('product_name')
            ->orderBy('product_id')
            ->orderBy('type')
            ->orderBy('reason')
            ->get();
    }
}
