<?php

namespace App\Filament\Resources\Inventory\Support;

use App\Models\Item;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Item balance (الرصيد) modal content: balance per warehouse and per batch.
 * Rendered as plain escaped HTML so the module needs no Blade views.
 */
class BalanceTable
{
    public static function forItem(Item $item): Htmlable
    {
        $warehouses = Warehouse::query()->get()->keyBy('id');
        $perWarehouse = StockService::warehouseBalances($item->id);

        $warehouseRows = $perWarehouse->map(fn ($row) => [
            $warehouses->get($row->warehouse_id)?->label ?? (string) $row->warehouse_id,
            static::qty($row->balance),
        ])->all();
        $warehouseRows[] = ['<strong>'.e(__('Total')).'</strong>', '<strong>'.static::qty($perWarehouse->sum('balance')).'</strong>'];

        $html = static::table([__('Warehouse'), __('Balance')], $warehouseRows, rawLast: true);

        if ($item->isBatchTracked()) {
            $batchRows = [];
            foreach ($perWarehouse as $row) {
                foreach (StockService::batchBalances($item->id, $row->warehouse_id) as $batch) {
                    $batchRows[] = [
                        $warehouses->get($row->warehouse_id)?->label ?? '',
                        (string) ($batch->batch_no ?? '-'),
                        $batch->expiry_date ? Carbon::parse($batch->expiry_date)->format('m/Y') : '-',
                        static::qty($batch->balance),
                    ];
                }
            }
            $html .= '<h3 style="margin:1rem 0 .5rem;font-weight:600">'.e(__('Balance per Batch')).'</h3>';
            $html .= static::table([__('Warehouse'), __('Batch No.'), __('Expiry Date'), __('Balance')], $batchRows);
        }

        return new HtmlString($html);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public static function table(array $headers, array $rows, bool $rawLast = false): string
    {
        $cell = 'padding:.4rem .6rem;border-bottom:1px solid rgba(127,127,127,.25);text-align:start';
        $html = '<table style="width:100%;border-collapse:collapse;font-size:.875rem"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th style="'.$cell.';font-weight:600">'.e($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        if ($rows === []) {
            $html .= '<tr><td colspan="'.count($headers).'" style="'.$cell.'">'.e(__('No balance')).'</td></tr>';
        }
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td style="'.$cell.'">'.($rawLast && $i === $last ? $value : e($value)).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    public static function qty(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ','), '0'), '.');
    }
}
