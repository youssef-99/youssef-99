<?php

namespace App\Filament\Resources\Inventory\Shared;

use App\Filament\Support\DocumentLines;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Services\StockService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory line grids that DocumentLines::stockRepeater() does not cover:
 * outgoing documents (negative adjustments, outbound transfers) pick an existing batch with stock.
 */
class StockLines
{
    /** Grid for documents that take stock out of the header warehouse. */
    public static function outgoingRepeater(string $relationship = 'lines'): Repeater
    {
        $columns = [
            TableColumn::make(__('Item'))->markAsRequired(),
            TableColumn::make(__('Batch No. / Expiry Date')),
            TableColumn::make(__('Balance')),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Item Price')),
            TableColumn::make(__('Discount %')),
            TableColumn::make(__('Net')),
        ];

        $schema = [
            DocumentLines::itemSelect('stock')->afterStateUpdated(function ($state, Get $get, Set $set) {
                $item = $state ? Item::query()->find($state) : null;
                $set('item_batch_id', $item?->isBatchTracked()
                    ? StockService::batchBalances($item->id, $get('../../warehouse_id'))->firstWhere('balance', '>', 0)?->item_batch_id
                    : null);
                $set('balance', $item ? StockService::balance($item->id, $get('../../warehouse_id')) : null);
            }),
            Hidden::make('unit_id'),
            static::batchSelect(),
            TextInput::make('balance')->label('Balance')->numeric()->readOnly()->dehydrated(false)
                ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state($get('item_id') ? StockService::balance((int) $get('item_id'), $get('../../warehouse_id'), $get('item_batch_id') ?: null) : null)),
            DocumentLines::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0.001),
            DocumentLines::calcInput('price', 'Item Price'),
            DocumentLines::calcInput('discount_percent', 'Discount %')->default(0)->maxValue(100),
            TextInput::make('net')->label('Net')->numeric()->readOnly(),
            Hidden::make('gross'),
            Hidden::make('discount'),
        ];

        return Repeater::make($relationship)
            ->label('Items')
            ->relationship($relationship)
            ->table($columns)
            ->schema($schema)
            ->defaultItems(0)
            ->addActionLabel(__('Add Item'))
            ->reorderable(false)
            ->cloneable()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => DocumentLines::updateTotals($get, $set))
            ->deleteAction(fn ($action) => $action->after(fn (Get $get, Set $set) => DocumentLines::updateTotals($get, $set)))
            ->columnSpanFull();
    }

    /** Batch picker with the batches in stock in the header warehouse, plus the line's current batch. */
    public static function batchSelect(): Select
    {
        return Select::make('item_batch_id')
            ->label('Batch No. / Expiry Date')
            ->options(function (Get $get) {
                if (! $get('item_id')) {
                    return [];
                }
                $options = StockService::batchBalances((int) $get('item_id'), $get('../../warehouse_id'))
                    ->filter(fn ($b) => $b->item_batch_id)
                    ->mapWithKeys(fn ($b) => [$b->item_batch_id => static::batchLabel($b->batch_no, $b->expiry_date).' ('.(float) $b->balance.')'])
                    ->all();
                if (($current = $get('item_batch_id')) && ! isset($options[$current]) && ($batch = ItemBatch::query()->find($current))) {
                    $options[$batch->id] = static::batchLabel($batch->batch_no, $batch->expiry_date);
                }

                return $options;
            })
            ->live()
            ->afterStateUpdated(fn ($state, Get $get, Set $set) => $set('balance', $get('item_id') ? StockService::balance((int) $get('item_id'), $get('../../warehouse_id'), $state ?: null) : null))
            ->placeholder('-');
    }

    public static function batchLabel(?string $batchNo, mixed $expiry): string
    {
        return ($batchNo ?? '-').' / '.($expiry ? Carbon::parse($expiry)->format('m/Y') : '-');
    }

    /**
     * Stocktake "Load Items": one line per item / batch with a balance in the warehouse,
     * counted quantity pre-filled with the book balance.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function stocktakeLinesFor(int $warehouseId, ?string $date = null): array
    {
        $rows = DB::table('stock_movements as m')
            ->leftJoin('item_batches as b', 'b.id', '=', 'm.item_batch_id')
            ->where('m.warehouse_id', $warehouseId)
            ->when($date, fn ($q) => $q->whereDate('m.date', '<=', Carbon::parse($date)->toDateString()))
            ->groupBy('m.item_id', 'm.item_batch_id', 'b.batch_no', 'b.expiry_date')
            ->havingRaw('SUM(m.qty_in - m.qty_out) <> 0')
            ->selectRaw('m.item_id, m.item_batch_id, b.batch_no, b.expiry_date, SUM(m.qty_in - m.qty_out) as balance')
            ->orderBy('m.item_id')
            ->get();

        $items = Item::query()->whereIn('id', $rows->pluck('item_id')->unique())->get()->keyBy('id');
        $lines = [];
        foreach ($rows as $row) {
            $item = $items->get($row->item_id);
            if (! $item) {
                continue;
            }
            $qty = round((float) $row->balance, 3);
            $price = StockService::unitCost($item);
            $lines[(string) Str::uuid()] = [
                'item_id' => $item->id,
                'unit_id' => $item->unit_id,
                'batch_no' => $row->batch_no,
                'expiry_date' => $row->expiry_date,
                'system_qty' => $qty,
                'quantity' => $qty,
                'price' => $price,
                'discount_percent' => 0,
            ];
        }

        return DocumentLines::recalculatedLines($lines);
    }
}
