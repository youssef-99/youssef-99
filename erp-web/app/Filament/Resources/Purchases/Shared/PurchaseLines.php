<?php

namespace App\Filament\Resources\Purchases\Shared;

use App\Filament\Support\DocumentLines;
use App\Models\Item;
use App\Services\Calc\DocumentCalculator;
use App\Services\Settings;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Purchase line grid (أصناف الفاتورة) with Discount, Discount 2, Bonus qty / %, Purchase Price,
 * Unit Net and Serial Numbers, plus the purchase totals block. Live amounts use DocumentCalculator;
 * PurchaseDocumentService recomputes everything on save.
 */
class PurchaseLines
{
    /**
     * @param  bool  $full  false for returns and orders: no Purchase Price / Unit Net / serial columns
     */
    public static function repeater(bool $full = true, string $relationship = 'lines'): Repeater
    {
        $columns = [
            TableColumn::make(__('Item'))->markAsRequired()->width('16rem'),
            TableColumn::make(__('Batch No.')),
            TableColumn::make(__('Expiry Date')),
            TableColumn::make(__('Quantity'))->markAsRequired(),
            TableColumn::make(__('Item Price'))->markAsRequired(),
            TableColumn::make(__('Gross Price')),
            TableColumn::make(__('Discount %')),
            TableColumn::make(__('Discount')),
            TableColumn::make(__('Discount 2 %')),
            TableColumn::make(__('Discount 2')),
            TableColumn::make(__('Bonus')),
            TableColumn::make(__('Bonus %')),
            TableColumn::make(__('Tax %')),
            TableColumn::make(__('Tax')),
        ];
        $schema = [
            static::itemSelect(),
            Hidden::make('unit_id'),
            TextInput::make('batch_no')->label('Batch No.')->maxLength(50),
            DatePicker::make('expiry_date')->label('Expiry Date')->native(false)->displayFormat('m/Y'),
            static::calcInput('quantity', 'Quantity')->default(1)->required()->minValue(0.001),
            static::calcInput('unit_price', 'Item Price')->required()->minValue(0),
            TextInput::make('gross')->label('Gross Price')->numeric()->readOnly(),
            static::calcInput('discount_percent', 'Discount %')->default(0)->minValue(0)->maxValue(100),
            TextInput::make('discount')->label('Discount')->numeric()->readOnly(),
            static::calcInput('discount2_percent', 'Discount 2 %')->default(0)->minValue(0)->maxValue(100),
            TextInput::make('discount2')->label('Discount 2')->numeric()->readOnly(),
            static::calcInput('bonus_qty', 'Bonus')->default(0)->minValue(0),
            static::calcInput('bonus_percent', 'Bonus %')->default(0)->minValue(0)->maxValue(100),
            static::calcInput('tax_percent', 'Tax %')->default(fn (): float => Settings::defaultVatRate()),
            TextInput::make('tax')->label('Tax')->numeric()->readOnly(),
        ];

        if ($full) {
            array_push($columns,
                TableColumn::make(__('Purchase Price')),
                TableColumn::make(__('Unit Net')),
                TableColumn::make(__('Item Net')),
                TableColumn::make(__('Serial Numbers'))->width('12rem'),
                TableColumn::make(__('Selling Price')),
            );
            array_push($schema,
                TextInput::make('purchase_price')->label('Purchase Price')->numeric()->readOnly()->dehydrated(false)
                    ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(static::purchasePrice($get))),
                TextInput::make('unit_net')->label('Unit Net')->numeric()->readOnly(),
                TextInput::make('net')->label('Item Net')->numeric()->readOnly(),
                TagsInput::make('serial_numbers')->label('Serial Numbers')->splitKeys(['Enter', ',', ' ']),
                TextInput::make('sale_price')->label('Selling Price')->numeric()->minValue(0),
            );
        } else {
            $schema[] = Hidden::make('unit_net');
            $schema[] = Hidden::make('net');
        }

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
            ->afterStateUpdated(fn (Get $get, Set $set) => DocumentLines::updateTotals($get, $set))
            ->deleteAction(fn ($action) => $action->after(fn (Get $get, Set $set) => DocumentLines::updateTotals($get, $set)))
            ->columnSpanFull();
    }

    public static function itemSelect(): Select
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
                ->mapWithKeys(fn (Item $i) => [$i->id => DocumentLines::itemLabel($i)])
                ->all())
            ->getOptionLabelUsing(fn ($value) => ($i = Item::query()->find($value)) ? DocumentLines::itemLabel($i) : null)
            ->live()
            ->afterStateUpdated(function ($state, Get $get, Set $set) {
                DocumentLines::fillFromItem($state ? Item::query()->find($state) : null, $get, $set, 'purchase');
                static::lineUpdated($get, $set);
            });
    }

    public static function calcInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set) => static::lineUpdated($get, $set));
    }

    /** Recompute one row, then the header totals. */
    public static function lineUpdated(Get $get, Set $set): void
    {
        $c = DocumentCalculator::line([
            'quantity' => $get('quantity'),
            'unit_price' => $get('unit_price'),
            'discount_percent' => $get('discount_percent'),
            'discount2_percent' => $get('discount2_percent'),
            'bonus_qty' => $get('bonus_qty'),
            'bonus_percent' => $get('bonus_percent'),
            'tax_percent' => $get('tax_percent'),
        ]);

        foreach (['gross', 'discount', 'discount2', 'tax', 'net', 'unit_net'] as $field) {
            $set($field, $c[$field]);
        }
        if ((float) $get('bonus_percent') > 0) {
            $set('bonus_qty', $c['bonus_qty']);
        }
        $set('purchase_price', static::purchasePrice($get, $c));

        DocumentLines::updateTotals($get, $set, '../../');
    }

    /** سعر الشراء: unit price after both discounts. */
    public static function purchasePrice(Get $get, ?array $calculated = null): ?float
    {
        $qty = (float) $get('quantity');
        if ($qty <= 0) {
            return null;
        }

        $calculated ??= DocumentCalculator::line([
            'quantity' => $qty,
            'unit_price' => $get('unit_price'),
            'discount_percent' => $get('discount_percent'),
            'discount' => $get('discount'),
            'discount2_percent' => $get('discount2_percent'),
            'discount2' => $get('discount2'),
        ]);

        return round($calculated['taxable'] / $qty, 4);
    }

    /** Purchase totals: Gross, Item Discounts, Additional Discount, Net, VAT, Net incl. VAT. */
    public static function totals(): Section
    {
        return Section::make(__('Totals'))
            ->schema([Grid::make(['default' => 2, 'md' => 3, 'xl' => 6])->schema([
                TextInput::make('gross_total')->label('Gross Price')->numeric()->readOnly()->default(0)->required(),
                TextInput::make('items_discount')->label('Item Discounts')->numeric()->readOnly()->default(0),
                TextInput::make('additional_discount')->label('Additional Discount')->numeric()->default(0)->minValue(0)
                    ->live(onBlur: true)->afterStateUpdated(fn (Get $get, Set $set) => DocumentLines::updateTotals($get, $set)),
                TextInput::make('net_total')->label('Net')->numeric()->readOnly()->default(0)->required(),
                TextInput::make('tax_total')->label('VAT')->numeric()->readOnly()->default(0)->required(),
                TextInput::make('grand_total')->label('Net incl. VAT')->numeric()->readOnly()->default(0)->required()->extraInputAttributes(['class' => 'font-bold']),
            ])])
            ->columnSpanFull();
    }
}
