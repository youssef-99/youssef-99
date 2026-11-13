<?php

namespace App\Filament\Support;

use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\SalesPolicy;
use App\Services\Calc\DocumentCalculator;
use App\Services\Settings;
use App\Services\StockService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Line-item grids (أصناف الفاتورة) and totals for sales, purchase and stock documents.
 * Live totals are computed with DocumentCalculator; the services recompute on save.
 *
 * Usage in a resource form:
 *   DocumentLines::scanner(),                 // optional barcode box (POS)
 *   DocumentLines::salesRepeater(),           // or purchaseRepeater() / stockRepeater()
 *   DocumentLines::totals(),                  // gross, discounts, net, VAT, grand total
 */
class DocumentLines
{
    public const TOTAL_FIELDS = ['gross_total', 'items_discount', 'additional_discount', 'net_total', 'tax_total', 'grand_total'];

    /* ------------------------------------------------------------------ item picker */

    public static function itemSelect(string $kind = 'sales'): Select
    {
        return Select::make('item_id')
            ->label('Item')
            ->required()
            ->searchable()
            ->getSearchResultsUsing(fn (?string $search) => Item::query()
                ->where('is_active', true)
                ->searchName($search)
                ->orderBy('code')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Item $i) => [$i->id => static::itemLabel($i)])
                ->all())
            ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? static::itemLabel($i) : null)
            ->live()
            ->afterStateUpdated(function ($state, Get $get, Set $set) use ($kind) {
                static::fillFromItem($state ? Item::query()->find($state) : null, $get, $set, $kind);
                static::lineUpdated($get, $set);
            });
    }

    public static function itemLabel(Item $item): string
    {
        return trim($item->code.' - '.$item->name.($item->barcode ? ' ['.$item->barcode.']' : ''));
    }

    /** Fill unit, price, VAT and FEFO batch when an item is chosen. */
    public static function fillFromItem(?Item $item, Get $get, Set $set, string $kind, string $headerPrefix = '../../'): void
    {
        if (! $item) {
            return;
        }

        $set('unit_id', $item->unit_id);
        $set('tax_percent', (float) $item->vat_rate);
        $set('quantity', $get('quantity') ?: 1);

        if ($kind === 'sales') {
            $set('unit_price', (float) $item->sale_price);
            if ($policyId = $get($headerPrefix.'sales_policy_id')) {
                $set('discount_percent', (float) SalesPolicy::query()->whereKey($policyId)->value('discount_percent'));
            }
            if ($item->isBatchTracked()) {
                $batch = StockService::batchBalances($item->id, $get($headerPrefix.'warehouse_id'))->firstWhere('balance', '>', 0);
                $set('item_batch_id', $batch?->item_batch_id);
                if ($batch && ($price = ItemBatch::query()->whereKey($batch->item_batch_id)->value('sale_price')) > 0) {
                    $set('unit_price', (float) $price);
                }
            }
            $set('balance', StockService::balance($item->id, $get($headerPrefix.'warehouse_id')));
        } elseif ($kind === 'purchase') {
            $set('unit_price', (float) $item->purchase_price);
            $set('sale_price', (float) $item->sale_price ?: null);
        } else {
            $set('price', StockService::unitCost($item));
        }
    }

    /** Batch picker showing "batch / expiry (balance)" for the chosen item. */
    public static function batchSelect(): Select
    {
        return Select::make('item_batch_id')
            ->label('Batch No. / Expiry Date')
            ->options(function (Get $get) {
                if (! $get('item_id')) {
                    return [];
                }

                return StockService::batchBalances((int) $get('item_id'), $get('../../warehouse_id'))
                    ->filter(fn ($b) => $b->item_batch_id)
                    ->mapWithKeys(fn ($b) => [$b->item_batch_id => $b->batch_no.' / '.($b->expiry_date ? Carbon::parse($b->expiry_date)->format('m/Y') : '-').' ('.(float) $b->balance.')'])
                    ->all();
            })
            ->placeholder('-');
    }

    /* ------------------------------------------------------------------ repeaters */

    public static function salesRepeater(string $relationship = 'lines', bool $withBatch = true, bool $withReturnedQty = false): Repeater
    {
        $columns = [
            TableColumn::make(__('Item'))->markAsRequired(),
        ];
        $schema = [
            static::itemSelect('sales'),
            Hidden::make('unit_id'),
        ];

        if ($withBatch) {
            $columns[] = TableColumn::make(__('Batch No. / Expiry Date'));
            $schema[] = static::batchSelect();
        }

        if ($withReturnedQty) {
            $columns[] = TableColumn::make(__('Qty Sold'));
            $schema[] = TextInput::make('sold_qty')->label('Qty Sold')->numeric()->readOnly()->dehydrated(false);
        }

        array_push($columns,
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Balance')),
            TableColumn::make(__('Unit Price')),
            TableColumn::make(__('Discount %')),
            TableColumn::make(__('Tax %')),
            TableColumn::make(__('Tax')),
            TableColumn::make(__('Item Net')),
        );
        array_push($schema,
            static::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0.001),
            TextInput::make('balance')->label('Balance')->numeric()->readOnly()->dehydrated(false)
                ->afterStateHydrated(fn (TextInput $c, Get $get) => $c->state($get('item_id') ? StockService::balance((int) $get('item_id'), $get('../../warehouse_id')) : null)),
            static::calcInput('unit_price', 'Unit Price')->required(),
            static::calcInput('discount_percent', 'Discount %')->default(0)->maxValue(100),
            static::calcInput('tax_percent', 'Tax %')->default(fn (): float => Settings::defaultVatRate()),
            TextInput::make('tax')->label('Tax')->numeric()->readOnly(),
            TextInput::make('net')->label('Item Net')->numeric()->readOnly(),
            Hidden::make('gross'),
            Hidden::make('discount'),
        );

        return static::repeater($relationship, $columns, $schema);
    }

    public static function purchaseRepeater(string $relationship = 'lines', bool $withPurchasePrice = true): Repeater
    {
        $columns = [
            TableColumn::make(__('Item'))->markAsRequired(),
            TableColumn::make(__('Batch No.')),
            TableColumn::make(__('Expiry Date')),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Item Price'))->markAsRequired(),
            TableColumn::make(__('Discount %')),
            TableColumn::make(__('Discount 2 %')),
            TableColumn::make(__('Bonus')),
            TableColumn::make(__('Tax %')),
            TableColumn::make(__('Tax')),
            TableColumn::make(__('Item Net')),
        ];
        $schema = [
            static::itemSelect('purchase'),
            Hidden::make('unit_id'),
            TextInput::make('batch_no')->label('Batch No.')->maxLength(50),
            DatePicker::make('expiry_date')->label('Expiry Date')->native(false)->displayFormat('m/Y'),
            static::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0.001),
            static::calcInput('unit_price', 'Item Price')->required(),
            static::calcInput('discount_percent', 'Discount %')->default(0)->maxValue(100),
            static::calcInput('discount2_percent', 'Discount 2 %')->default(0)->maxValue(100),
            static::calcInput('bonus_qty', 'Bonus')->default(0),
            static::calcInput('tax_percent', 'Tax %')->default(fn (): float => Settings::defaultVatRate()),
            TextInput::make('tax')->label('Tax')->numeric()->readOnly(),
            TextInput::make('net')->label('Item Net')->numeric()->readOnly(),
            Hidden::make('gross'),
            Hidden::make('discount'),
            Hidden::make('discount2'),
            Hidden::make('unit_net'),
        ];

        if ($withPurchasePrice) {
            $columns[] = TableColumn::make(__('Selling Price'));
            $schema[] = TextInput::make('sale_price')->label('Selling Price')->numeric();
        }

        return static::repeater($relationship, $columns, $schema);
    }

    /** Adjustments, transfers and stocktakes. */
    public static function stockRepeater(string $relationship = 'lines', bool $stocktake = false): Repeater
    {
        $columns = [
            TableColumn::make(__('Item'))->markAsRequired(),
            TableColumn::make(__('Batch No.')),
            TableColumn::make(__('Expiry Date')),
        ];
        $schema = [
            static::itemSelect('stock'),
            Hidden::make('unit_id'),
            TextInput::make('batch_no')->label('Batch No.')->maxLength(50),
            DatePicker::make('expiry_date')->label('Expiry Date')->native(false)->displayFormat('m/Y'),
        ];

        if ($stocktake) {
            $columns[] = TableColumn::make(__('Book Balance'));
            $schema[] = TextInput::make('system_qty')->label('Book Balance')->numeric()->readOnly();
        }

        array_push($columns,
            TableColumn::make($stocktake ? __('Counted Quantity') : __('Quantity'))->markAsRequired(),
            TableColumn::make(__('Item Price')),
            TableColumn::make(__('Discount %')),
            TableColumn::make(__('Net')),
        );
        array_push($schema,
            static::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0),
            static::calcInput('price', 'Item Price'),
            static::calcInput('discount_percent', 'Discount %')->default(0)->maxValue(100),
            TextInput::make('net')->label('Net')->numeric()->readOnly(),
            Hidden::make('gross'),
            Hidden::make('discount'),
        );

        return static::repeater($relationship, $columns, $schema);
    }

    protected static function repeater(string $relationship, array $columns, array $schema): Repeater
    {
        return Repeater::make($relationship)
            ->label('Invoice Items')
            ->relationship($relationship)
            ->table($columns)
            ->schema($schema)
            ->defaultItems(0)
            ->addActionLabel(__('Add Item'))
            ->reorderable(false)
            ->cloneable()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotals($get, $set))
            ->deleteAction(fn ($action) => $action->after(fn (Get $get, Set $set) => static::updateTotals($get, $set)))
            ->columnSpanFull();
    }

    public static function calcInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set) => static::lineUpdated($get, $set));
    }

    /* ------------------------------------------------------------------ scanner */

    /** Barcode / GTIN / item-code box that appends a line (or bumps quantity). */
    public static function scanner(string $kind = 'sales', string $relationship = 'lines'): TextInput
    {
        return TextInput::make('scan')
            ->label('Scan barcode / item code')
            ->prefixIcon('heroicon-o-qr-code')
            ->dehydrated(false)
            ->live(onBlur: true)
            ->extraInputAttributes(['autocomplete' => 'off'])
            ->afterStateUpdated(function (?string $state, Get $get, Set $set) use ($kind, $relationship) {
                $code = trim((string) $state);
                if ($code === '') {
                    return;
                }
                $item = Item::query()->where('is_active', true)
                    ->where(fn ($q) => $q->where('barcode', $code)->orWhere('gtin', $code)->orWhere('code', $code))
                    ->first();
                $set('scan', null);
                if (! $item) {
                    Notification::make()->warning()->title(__('Item not found: :code', ['code' => $code]))->send();

                    return;
                }

                $lines = $get($relationship) ?? [];
                foreach ($lines as $key => $line) {
                    if ((int) ($line['item_id'] ?? 0) === $item->id) {
                        $lines[$key]['quantity'] = (float) ($line['quantity'] ?? 0) + 1;
                        $set($relationship, static::recalculatedLines($lines));
                        static::updateTotals($get, $set);

                        return;
                    }
                }

                $line = ['item_id' => $item->id, 'unit_id' => $item->unit_id, 'quantity' => 1, 'tax_percent' => (float) $item->vat_rate, 'discount_percent' => 0];
                if ($kind === 'sales') {
                    $line['unit_price'] = (float) $item->sale_price;
                    $line['balance'] = StockService::balance($item->id, $get('warehouse_id'));
                    if ($item->isBatchTracked()) {
                        $line['item_batch_id'] = StockService::batchBalances($item->id, $get('warehouse_id'))->firstWhere('balance', '>', 0)?->item_batch_id;
                    }
                    if ($policy = $get('sales_policy_id')) {
                        $line['discount_percent'] = (float) SalesPolicy::query()->whereKey($policy)->value('discount_percent');
                    }
                } elseif ($kind === 'purchase') {
                    $line['unit_price'] = (float) $item->purchase_price;
                } else {
                    $line['price'] = StockService::unitCost($item);
                }

                $lines[(string) Str::uuid()] = $line;
                $set($relationship, static::recalculatedLines($lines));
                static::updateTotals($get, $set);
            });
    }

    /* ------------------------------------------------------------------ totals */

    /**
     * @param  bool  $withTax  false for stock documents
     */
    public static function totals(bool $withTax = true, bool $percentDiscount = true): Section
    {
        $fields = [
            TextInput::make('gross_total')->label('Gross Price')->numeric()->readOnly()->default(0),
            TextInput::make('items_discount')->label('Item Discounts')->numeric()->readOnly()->default(0),
            TextInput::make('additional_discount')->label('Additional Discount')->numeric()->default(0)->minValue(0)
                ->live(onBlur: true)->afterStateUpdated(function (Get $get, Set $set) {
                    $set('additional_discount_percent', 0);
                    static::updateTotals($get, $set);
                }),
        ];

        if ($percentDiscount) {
            $fields[] = TextInput::make('additional_discount_percent')->label('Additional Discount %')->numeric()->default(0)->minValue(0)->maxValue(100)->suffix('%')
                ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotals($get, $set));
        }

        $fields[] = TextInput::make('net_total')->label('Net Price')->numeric()->readOnly()->default(0);

        if ($withTax) {
            $fields[] = TextInput::make('tax_total')->label('Total Tax')->numeric()->readOnly()->default(0);
            $fields[] = TextInput::make('grand_total')->label('Net After Tax')->numeric()->readOnly()->default(0)->extraInputAttributes(['class' => 'font-bold']);
        }

        return Section::make(__('Totals'))
            ->schema([Grid::make(['default' => 2, 'md' => 4])->schema($fields)])
            ->columnSpanFull();
    }

    /** Called from a field inside a repeater row. */
    public static function lineUpdated(Get $get, Set $set): void
    {
        $c = DocumentCalculator::line([
            'quantity' => $get('quantity'),
            'unit_price' => $get('unit_price') ?? $get('price'),
            'discount_percent' => $get('discount_percent'),
            'discount2_percent' => $get('discount2_percent'),
            'bonus_qty' => $get('bonus_qty'),
            'tax_percent' => $get('tax_percent'),
        ]);
        $set('gross', $c['gross']);
        $set('discount', $c['discount']);
        $set('tax', $c['tax']);
        $set('net', $c['net']);
        if ($get('discount2_percent') !== null) {
            $set('discount2', $c['discount2']);
            $set('unit_net', $c['unit_net']);
        }

        static::updateTotals($get, $set, '../../');
    }

    public static function recalculatedLines(array $lines): array
    {
        foreach ($lines as $key => $line) {
            $line['unit_price'] ??= $line['price'] ?? 0;
            $c = DocumentCalculator::line($line);
            $lines[$key] = array_merge($lines[$key], ['gross' => $c['gross'], 'discount' => $c['discount'], 'tax' => $c['tax'], 'net' => $c['net']]);
        }

        return $lines;
    }

    /** Recompute header totals; $p is the path prefix to the header ('' or '../../'). */
    public static function updateTotals(Get $get, Set $set, string $p = ''): void
    {
        $lines = collect($get($p.'lines') ?? [])->map(function ($l) {
            $l['unit_price'] ??= $l['price'] ?? 0;

            return $l;
        })->all();

        $t = DocumentCalculator::totals($lines, $get($p.'additional_discount'), $get($p.'additional_discount_percent'));

        foreach (self::TOTAL_FIELDS as $field) {
            $set($p.$field, $t[$field]);
        }

        // POS payment helpers
        $received = (float) $get($p.'amount_received');
        if ($received > 0) {
            $set($p.'change_due', max(0, round($received - $t['grand_total'], 2)));
        }
    }
}
