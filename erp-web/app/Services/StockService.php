<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The stock ledger. Balances are SUM(qty_in - qty_out) over stock_movements.
 * Costing: moving weighted average on the item (items.average_cost).
 */
class StockService
{
    public static function balance(int $itemId, ?int $warehouseId = null, ?int $batchId = null, ?int $branchId = null, ?string $upToDate = null): float
    {
        return round((float) StockMovement::query()
            ->where('item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($batchId, fn ($q) => $q->where('item_batch_id', $batchId))
            ->when($upToDate, fn ($q) => $q->where('date', '<=', $upToDate))
            ->sum(DB::raw('qty_in - qty_out')), 3);
    }

    /** @return Collection<int, object{item_batch_id:int|null, batch_no:string|null, expiry_date:string|null, balance:float}> */
    public static function batchBalances(int $itemId, ?int $warehouseId = null): Collection
    {
        return DB::table('stock_movements as m')
            ->leftJoin('item_batches as b', 'b.id', '=', 'm.item_batch_id')
            ->where('m.item_id', $itemId)
            ->when($warehouseId, fn ($q) => $q->where('m.warehouse_id', $warehouseId))
            ->groupBy('m.item_batch_id', 'b.batch_no', 'b.expiry_date')
            ->havingRaw('SUM(m.qty_in - m.qty_out) <> 0')
            ->orderBy('b.expiry_date')
            ->selectRaw('m.item_batch_id, b.batch_no, b.expiry_date, SUM(m.qty_in - m.qty_out) as balance')
            ->get();
    }

    /** Find or create the batch for an item (only for batch-tracked items). */
    public static function resolveBatch(Item $item, ?string $batchNo, $expiryDate = null): ?ItemBatch
    {
        if (blank($batchNo) && blank($expiryDate)) {
            return null;
        }

        return ItemBatch::query()->firstOrCreate([
            'item_id' => $item->id,
            'batch_no' => $batchNo ?: '-',
            'expiry_date' => $expiryDate ? Carbon::parse($expiryDate)->toDateString() : null,
        ]);
    }

    public static function removeFor(Model $source): void
    {
        StockMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->delete();
    }

    /**
     * Record a movement. Positive $qty = in, negative = out.
     * For incoming movements with a cost the item's moving average cost is updated.
     */
    public static function move(Model $source, string $type, Item $item, int $branchId, int $warehouseId, $date, float $qty, float $unitCost = 0, ?int $batchId = null, bool $allowNegative = true): StockMovement
    {
        if ($item->item_nature === 'service' || $item->item_type === 'service') {
            // services do not move stock
            return new StockMovement;
        }

        if ($qty < 0 && ! $allowNegative) {
            $available = static::balance($item->id, $warehouseId, $batchId);
            if ($available + $qty < -0.0001) {
                throw new ErpException(__('Insufficient stock for :item: available :available, required :required.', [
                    'item' => $item->label, 'available' => $available, 'required' => abs($qty),
                ]));
            }
        }

        if ($qty > 0 && $unitCost > 0) {
            $onHand = max(0, static::balance($item->id));
            $currentCost = (float) $item->average_cost;
            $newCost = ($onHand + $qty) > 0 ? (($onHand * $currentCost) + ($qty * $unitCost)) / ($onHand + $qty) : $unitCost;
            $item->forceFill(['average_cost' => round($newCost, 4)])->saveQuietly();
        }

        if ($qty < 0 && $unitCost <= 0) {
            $unitCost = (float) $item->average_cost ?: (float) $item->purchase_price;
        }

        $movement = new StockMovement([
            'item_id' => $item->id,
            'item_batch_id' => $batchId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'date' => Carbon::parse($date)->toDateString(),
            'movement_type' => $type,
            'qty_in' => $qty > 0 ? $qty : 0,
            'qty_out' => $qty < 0 ? -$qty : 0,
            'unit_cost' => round($unitCost, 4),
        ]);
        $movement->source()->associate($source);
        $movement->save();

        return $movement;
    }

    /** Current unit cost used for COGS. */
    public static function unitCost(Item $item): float
    {
        return (float) ($item->average_cost > 0 ? $item->average_cost : $item->purchase_price);
    }
}
