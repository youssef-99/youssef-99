<?php

namespace App\Filament\Resources\Pos\Support;

use App\Filament\Support\DocumentLines;
use App\Models\Item;
use App\Models\ItemBatch;
use App\Models\PointsCustomer;
use App\Models\SalesDocument;
use App\Services\Calc\DocumentCalculator;
use App\Services\LoyaltyPointsService;
use App\Services\PosService;
use App\Services\PromotionService;
use App\Services\StockService;
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
use Illuminate\Support\Str;

/**
 * POS line grid (أصناف الفاتورة), barcode scanner, totals and payment box (cash, Visa / Mada,
 * amount received, change due). Promotions are applied whenever a line is added or changed.
 */
class PosLines
{
    /** Display-only columns filled from the item. */
    public const INFO_FIELDS = ['item_code', 'barcode', 'gtin', 'unit_name', 'balance', 'total_balance', 'unit_price_incl_tax'];

    /* ------------------------------------------------------------------ line data */

    /**
     * Fill a line array from its item: unit, price, VAT, FEFO batch, balances and the active promotion.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function fillLine(array $line, ?Item $item, ?int $warehouseId, ?int $branchId, mixed $date, bool $resetPrice = true): array
    {
        if (! $item) {
            return $line;
        }

        $line['item_id'] = $item->id;
        $line['unit_id'] = $item->unit_id;
        $line['quantity'] = (float) ($line['quantity'] ?? 0) ?: 1;
        $line['tax_percent'] = (float) $item->vat_rate;

        if ($resetPrice) {
            $line['unit_price'] = (float) $item->sale_price;
            $line['discount_percent'] = 0;
            $line['free_qty'] = 0;
        }

        if ($item->isBatchTracked() && blank($line['item_batch_id'] ?? null)) {
            $line['item_batch_id'] = StockService::batchBalances($item->id, $warehouseId)->firstWhere('balance', '>', 0)?->item_batch_id;
        }

        $line = PromotionService::applyToLine($line, $branchId, $date);

        return static::calculate(array_merge($line, static::info($item, $warehouseId, $line)));
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function info(?Item $item, ?int $warehouseId, array $line = []): array
    {
        if (! $item) {
            return array_fill_keys(self::INFO_FIELDS, null);
        }

        $price = (float) ($line['unit_price'] ?? $item->sale_price);
        $tax = (float) ($line['tax_percent'] ?? $item->vat_rate);

        return [
            'item_code' => $item->code,
            'barcode' => $item->barcode,
            'gtin' => $item->gtin,
            'unit_name' => $item->unit?->name,
            'balance' => StockService::balance($item->id, $warehouseId),
            'total_balance' => StockService::balance($item->id),
            'unit_price_incl_tax' => round($price * (1 + $tax / 100), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function calculate(array $line): array
    {
        $c = DocumentCalculator::line($line);
        $line['gross'] = $c['gross'];
        $line['discount'] = $c['discount'];
        $line['tax'] = $c['tax'];
        $line['net'] = $c['net'];
        $line['unit_price_incl_tax'] = round((float) ($line['unit_price'] ?? 0) * (1 + (float) ($line['tax_percent'] ?? 0) / 100), 2);

        return $line;
    }

    /* ------------------------------------------------------------------ components */

    public static function scanner(): TextInput
    {
        return TextInput::make('scan')
            ->label('Scan barcode / item code')
            ->prefixIcon('heroicon-o-qr-code')
            ->dehydrated(false)
            ->autofocus()
            ->live(onBlur: true)
            ->extraInputAttributes(['autocomplete' => 'off'])
            ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                $code = trim((string) $state);
                $set('scan', null);
                if ($code === '') {
                    return;
                }

                $item = Item::query()->where('is_active', true)
                    ->where(fn ($q) => $q->where('barcode', $code)->orWhere('gtin', $code)->orWhere('code', $code))
                    ->first();
                if (! $item) {
                    Notification::make()->warning()->title(__('Item not found: :code', ['code' => $code]))->send();

                    return;
                }

                static::addItem($item, $get, $set);
            });
    }

    /** Append the item (or bump its quantity). $get / $set are at the form root. */
    public static function addItem(Item $item, Get $get, Set $set): void
    {
        $warehouseId = $get('warehouse_id') ? (int) $get('warehouse_id') : null;
        $branchId = $get('branch_id') ? (int) $get('branch_id') : null;
        $lines = $get('lines') ?? [];

        $key = collect($lines)->search(fn ($line) => (int) ($line['item_id'] ?? 0) === $item->id);
        if ($key !== false) {
            $lines[$key]['quantity'] = (float) ($lines[$key]['quantity'] ?? 0) + 1;
            $lines[$key] = static::fillLine($lines[$key], $item, $warehouseId, $branchId, $get('date'), resetPrice: false);
        } else {
            $lines[(string) Str::uuid()] = static::fillLine(['quantity' => 1], $item, $warehouseId, $branchId, $get('date'));
        }

        $set('lines', $lines);
        static::updateTotals($get, $set);
    }

    public static function repeater(): Repeater
    {
        $readOnly = fn (string $name, string $label) => TextInput::make($name)->label($label)->readOnly()->dehydrated(false)
            ->afterStateHydrated(function (TextInput $component, Get $get) use ($name) {
                if ($component->getState() === null && $get('item_id')) {
                    $info = static::info(Item::query()->with('unit')->find($get('item_id')), $get('../../warehouse_id') ? (int) $get('../../warehouse_id') : null, ['unit_price' => $get('unit_price'), 'tax_percent' => $get('tax_percent')]);
                    $component->state($info[$name] ?? null);
                }
            });

        $columns = [
            TableColumn::make(__('Item Name'))->markAsRequired(),
            TableColumn::make(__('Item Code')),
            TableColumn::make(__('Item Barcode')),
            TableColumn::make(__('GTIN')),
            TableColumn::make(__('Unit')),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Balance')),
            TableColumn::make(__('Total Balance')),
            TableColumn::make(__('Unit Price'))->markAsRequired(),
            TableColumn::make(__('Gross Price')),
            TableColumn::make(__('Unit Price incl. Tax')),
            TableColumn::make(__('Tax')),
            TableColumn::make(__('Tax %')),
            TableColumn::make(__('Item Net')),
            TableColumn::make(__('Batch No. / Expiry Date')),
        ];

        $schema = [
            Select::make('item_id')
                ->label('Item Name')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search) => Item::query()->where('is_active', true)->searchName($search)->orderBy('code')->limit(50)->get()
                    ->mapWithKeys(fn (Item $i) => [$i->id => DocumentLines::itemLabel($i)])->all())
                ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? DocumentLines::itemLabel($i) : null)
                ->live()
                ->afterStateUpdated(function ($state, Get $get, Set $set) {
                    $item = $state ? Item::query()->with('unit')->find($state) : null;
                    $line = static::fillLine(
                        ['quantity' => $get('quantity'), 'item_batch_id' => null],
                        $item,
                        $get('../../warehouse_id') ? (int) $get('../../warehouse_id') : null,
                        $get('../../branch_id') ? (int) $get('../../branch_id') : null,
                        $get('../../date'),
                    );
                    foreach ($line as $field => $value) {
                        $set($field, $value);
                    }
                    static::updateTotals($get, $set, '../../');
                }),
            Hidden::make('unit_id'),
            Hidden::make('promotion_id'),
            Hidden::make('free_qty')->default(0),
            Hidden::make('discount_percent')->default(0),
            Hidden::make('discount'),
            $readOnly('item_code', 'Item Code'),
            $readOnly('barcode', 'Item Barcode'),
            $readOnly('gtin', 'GTIN'),
            $readOnly('unit_name', 'Unit'),
            static::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0.001),
            $readOnly('balance', 'Balance'),
            $readOnly('total_balance', 'Total Balance'),
            static::calcInput('unit_price', 'Unit Price')->required()->minValue(0),
            TextInput::make('gross')->label('Gross Price')->numeric()->readOnly(),
            $readOnly('unit_price_incl_tax', 'Unit Price incl. Tax'),
            TextInput::make('tax')->label('Tax')->numeric()->readOnly(),
            static::calcInput('tax_percent', 'Tax %')->default(config('erp.default_vat_rate')),
            TextInput::make('net')->label('Item Net')->numeric()->readOnly(),
            DocumentLines::batchSelect(),
        ];

        return Repeater::make('lines')
            ->label('Invoice Items')
            ->relationship('lines')
            ->table($columns)
            ->schema($schema)
            ->defaultItems(0)
            ->minItems(1)
            ->addActionLabel(__('Add Item'))
            ->reorderable(false)
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
            ->afterStateUpdated(function (Get $get, Set $set) {
                $line = [
                    'item_id' => $get('item_id'),
                    'quantity' => $get('quantity'),
                    'unit_price' => $get('unit_price'),
                    'discount_percent' => $get('discount_percent'),
                    'tax_percent' => $get('tax_percent'),
                    'free_qty' => $get('free_qty'),
                    'promotion_id' => $get('promotion_id'),
                ];
                $line = static::calculate(PromotionService::applyToLine($line, $get('../../branch_id') ? (int) $get('../../branch_id') : null, $get('../../date')));
                foreach (['unit_price', 'discount_percent', 'free_qty', 'promotion_id', 'gross', 'discount', 'tax', 'net', 'unit_price_incl_tax'] as $field) {
                    $set($field, $line[$field] ?? null);
                }
                static::updateTotals($get, $set, '../../');
            });
    }

    /** Totals (gross, additional discount amount and %, net, VAT, net after tax). */
    public static function totals(): Section
    {
        return Section::make(__('Totals'))
            ->schema([Grid::make(['default' => 2, 'md' => 3, 'xl' => 6])->schema([
                TextInput::make('gross_total')->label('Gross Price')->numeric()->readOnly()->default(0),
                TextInput::make('additional_discount')->label('Additional Discount')->numeric()->default(0)->minValue(0)
                    ->live(onBlur: true)->afterStateUpdated(function (Get $get, Set $set) {
                        $set('additional_discount_percent', 0);
                        static::updateTotals($get, $set);
                    }),
                TextInput::make('additional_discount_percent')->label('Additional Discount %')->numeric()->default(0)->minValue(0)->maxValue(100)->suffix('%')
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotals($get, $set)),
                TextInput::make('net_total')->label('Net Price')->numeric()->readOnly()->default(0),
                TextInput::make('tax_total')->label('Total Tax')->numeric()->readOnly()->default(0),
                TextInput::make('grand_total')->label('Net After Tax')->numeric()->readOnly()->default(0)->extraInputAttributes(['class' => 'font-bold']),
                Hidden::make('items_discount')->default(0),
            ])])
            ->columnSpanFull();
    }

    /** Cash, Visa / Mada, amount received, change due (المبلغ الباقي). */
    public static function payment(): Section
    {
        $live = fn (TextInput $input) => $input->numeric()->default(0)->minValue(0)->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set) => static::updatePayment($get, $set));

        return Section::make(__('Payment'))
            ->schema([Grid::make(['default' => 2, 'md' => 4])->schema([
                $live(TextInput::make('cash_amount')->label('Cash')),
                $live(TextInput::make('card_amount')->label('Visa / Mada')),
                $live(TextInput::make('amount_received')->label('Amount Received')),
                TextInput::make('change_due')->label('Change Due')->numeric()->readOnly()->default(0)->extraInputAttributes(['class' => 'font-bold']),
            ])])
            ->columnSpanFull();
    }

    /** Loyalty points redemption box. */
    public static function pointsRedemption(): TextInput
    {
        return TextInput::make('points_redeemed')
            ->label('Redeem Points')
            ->numeric()
            ->default(0)
            ->minValue(0)
            ->live(onBlur: true)
            ->helperText(fn (Get $get) => ($customer = $get('points_customer_id') ? PointsCustomer::query()->find($get('points_customer_id')) : null)
                ? __('Balance: :points points (1 point = :value)', ['points' => number_format((float) $customer->points_balance, 2), 'value' => LoyaltyPointsService::pointValue()])
                : null)
            ->afterStateUpdated(function ($state, $old, Get $get, Set $set) {
                $delta = LoyaltyPointsService::discountFor((float) $state) - LoyaltyPointsService::discountFor((float) $old);
                if ((float) $get('additional_discount_percent') > 0) {
                    $set('additional_discount_percent', 0);
                }
                $set('additional_discount', max(0, round((float) $get('additional_discount') + $delta, 2)));
                $set('points_discount', LoyaltyPointsService::discountFor((float) $state));
                static::updateTotals($get, $set);
            });
    }

    /** Recompute header totals and change due. $p is the prefix to the form root ('' or '../../'). */
    public static function updateTotals(Get $get, Set $set, string $p = ''): void
    {
        $lines = collect($get($p.'lines') ?? [])->all();
        $t = DocumentCalculator::totals($lines, $get($p.'additional_discount'), $get($p.'additional_discount_percent'));

        foreach (DocumentLines::TOTAL_FIELDS as $field) {
            $set($p.$field, $t[$field]);
        }

        static::updatePayment($get, $set, $p);
    }

    public static function updatePayment(Get $get, Set $set, string $p = ''): void
    {
        $payment = PosService::payment((float) $get($p.'grand_total'), (float) $get($p.'cash_amount'), (float) $get($p.'card_amount'), (float) $get($p.'amount_received'));
        $set($p.'change_due', $payment['change_due']);
    }

    /* ------------------------------------------------------------------ returns */

    /** Return lines: Qty Sold / Qty Returned (earlier returns) / Quantity to return. */
    public static function returnRepeater(): Repeater
    {
        $returnInfo = function (string $field) {
            return function (TextInput $component, Get $get) use ($field) {
                $original = $get('../../original_document_id') ? SalesDocument::query()->find($get('../../original_document_id')) : null;
                if (! $original || ! $get('item_id')) {
                    return;
                }
                $returnId = data_get($component->getLivewire(), 'record.id');
                $row = PosService::returnableQuantities($original, $returnId ? (int) $returnId : null)[PosService::lineKey($get('item_id'), $get('item_batch_id'))] ?? null;
                $component->state($row[$field] ?? 0);
            };
        };

        $columns = [
            TableColumn::make(__('Item Name')),
            TableColumn::make(__('Item Code')),
            TableColumn::make(__('Item Barcode')),
            TableColumn::make(__('GTIN')),
            TableColumn::make(__('Unit')),
            TableColumn::make(__('Qty Sold')),
            TableColumn::make(__('Qty Returned')),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Unit Price')),
            TableColumn::make(__('Gross Price')),
            TableColumn::make(__('Tax')),
            TableColumn::make(__('Tax %')),
            TableColumn::make(__('Batch No. / Expiry Date')),
        ];

        $info = fn (string $name, string $label) => TextInput::make($name)->label($label)->readOnly()->dehydrated(false)
            ->afterStateHydrated(function (TextInput $component, Get $get) use ($name) {
                if ($component->getState() === null && $get('item_id')) {
                    $component->state(static::info(Item::query()->with('unit')->find($get('item_id')), null)[$name] ?? null);
                }
            });

        $schema = [
            Select::make('item_id')
                ->label('Item Name')
                ->required()
                ->disabled()
                ->dehydrated()
                ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? DocumentLines::itemLabel($i) : null)
                ->options(fn (Get $get) => $get('item_id') && ($i = Item::query()->find($get('item_id'))) ? [$i->id => DocumentLines::itemLabel($i)] : []),
            Hidden::make('unit_id'),
            Hidden::make('discount_percent')->default(0),
            Hidden::make('discount'),
            Hidden::make('net'),
            $info('item_code', 'Item Code'),
            $info('barcode', 'Item Barcode'),
            $info('gtin', 'GTIN'),
            $info('unit_name', 'Unit'),
            TextInput::make('sold_qty')->label('Qty Sold')->numeric()->readOnly()->dehydrated(false)->afterStateHydrated($returnInfo('sold')),
            TextInput::make('returned_qty')->label('Qty Returned')->numeric()->readOnly()->dehydrated(false)->afterStateHydrated($returnInfo('returned')),
            TextInput::make('quantity')->label('Quantity')->numeric()->required()->minValue(0.001)
                ->maxValue(fn (Get $get) => $get('sold_qty') !== null ? max(0, (float) $get('sold_qty') - (float) $get('returned_qty')) : null)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    $line = static::calculate(['quantity' => $get('quantity'), 'unit_price' => $get('unit_price'), 'discount_percent' => $get('discount_percent'), 'tax_percent' => $get('tax_percent')]);
                    foreach (['gross', 'discount', 'tax', 'net'] as $field) {
                        $set($field, $line[$field]);
                    }
                    static::updateTotals($get, $set, '../../');
                }),
            TextInput::make('unit_price')->label('Unit Price')->numeric()->readOnly(),
            TextInput::make('gross')->label('Gross Price')->numeric()->readOnly(),
            TextInput::make('tax')->label('Tax')->numeric()->readOnly(),
            TextInput::make('tax_percent')->label('Tax %')->numeric()->readOnly(),
            Select::make('item_batch_id')
                ->label('Batch No. / Expiry Date')
                ->disabled()
                ->dehydrated()
                ->options(fn (Get $get) => $get('item_id') ? ItemBatch::query()->where('item_id', $get('item_id'))->get()
                    ->mapWithKeys(fn ($b) => [$b->id => $b->batch_no.' / '.($b->expiry_date?->format('m/Y') ?? '-')])->all() : [])
                ->placeholder('-'),
        ];

        return Repeater::make('lines')
            ->label('Invoice Items')
            ->relationship('lines')
            ->table($columns)
            ->schema($schema)
            ->defaultItems(0)
            ->minItems(1)
            ->addable(false)
            ->reorderable(false)
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotals($get, $set))
            ->deleteAction(fn ($action) => $action->after(fn (Get $get, Set $set) => static::updateTotals($get, $set)))
            ->columnSpanFull();
    }

    /** Copy the returnable lines of the original POS invoice into the return form. */
    public static function fillFromOriginal(?int $originalId, Get $get, Set $set): void
    {
        $original = $originalId ? SalesDocument::query()->with('lines.item.unit')->find($originalId) : null;
        if (! $original) {
            $set('lines', []);

            return;
        }

        $available = PosService::returnableQuantities($original);
        $lines = [];
        foreach ($original->lines as $line) {
            $row = $available[PosService::lineKey($line->item_id, $line->item_batch_id)] ?? null;
            if (! $row || $row['remaining'] <= 0 || collect($lines)->contains(fn ($l) => PosService::lineKey($l['item_id'], $l['item_batch_id']) === PosService::lineKey($line->item_id, $line->item_batch_id))) {
                continue;
            }
            $lines[(string) Str::uuid()] = static::calculate(array_merge(
                $line->only(['item_id', 'unit_id', 'item_batch_id', 'unit_price', 'discount_percent', 'tax_percent']),
                static::info($line->item, null),
                ['quantity' => $row['remaining'], 'sold_qty' => $row['sold'], 'returned_qty' => $row['returned']],
            ));
        }

        $set('lines', $lines);
        $set('branch_id', $original->branch_id);
        $set('warehouse_id', $original->warehouse_id);
        $set('points_customer_id', $original->points_customer_id);
        $set('doctor_id', $original->doctor_id);
        $base = (float) $original->net_total + (float) $original->additional_discount;
        $set('additional_discount', 0);
        $set('additional_discount_percent', $base > 0 ? round((float) $original->additional_discount / $base * 100, 4) : 0);
        static::updateTotals($get, $set);
    }
}
