<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemBatchResource\Pages\ManageItemBatches;
use App\Filament\Resources\Inventory\Support\BalanceTable;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\ItemBatch;
use App\Models\StockMovement;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Batch expiry dates (تواريخ صلاحية التشغيلات) — /inventory/item-batch-expiry-dates.
 * Batches are created by purchases and stock documents; here their description, expiry and selling price can be corrected.
 */
class ItemBatchResource extends ErpResource
{
    /** Days before expiry that are highlighted as "near expiry". */
    public const NEAR_EXPIRY_DAYS = 90;

    protected static ?string $model = ItemBatch::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.item-batch-expiry-dates';

    protected static ?string $modelLabel = 'Batch Expiry Date';

    protected static ?string $pluralModelLabel = 'Batch Expiry Dates';

    protected static ?string $slug = 'inventory/item-batch-expiry-dates';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 70;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('batch_no')->label('Batch No.')->required()->maxLength(50),
            DatePicker::make('expiry_date')->label('Expiry Date')->native(false)->displayFormat('m/Y'),
            TextInput::make('description')->label('Description')->maxLength(255)->columnSpanFull(),
            Fields::money('sale_price', 'Selling Price')->minValue(0),
        ]);
    }

    public static function balanceSubquery(): Builder
    {
        return StockMovement::query()
            ->selectRaw('COALESCE(SUM(qty_in - qty_out), 0)')
            ->whereColumn('stock_movements.item_batch_id', 'item_batches.id');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('item_batches.*')
                ->addSelect(['balance' => static::balanceSubquery()])
                ->with('item.unit'))
            ->columns([
                TextColumn::make('item.code')->label('Item Code')->searchable()->sortable(),
                Columns::name('Item Name', 'item'),
                TextColumn::make('description')->label('Description')->searchable()->toggleable(),
                TextColumn::make('batch_no')->label('Batch No.')->searchable()->sortable(),
                TextColumn::make('expiry_date')->label('Expiry Date')->date('m/Y')->sortable()
                    ->badge()
                    ->color(fn (ItemBatch $record) => static::expiryColor($record)),
                TextColumn::make('item.vat_rate')->label('Tax %')->numeric(2)->suffix('%'),
                Columns::money('last_purchase_price', 'Last Purchase Price'),
                TextColumn::make('selling_price')->label('Selling Price')
                    ->state(fn (ItemBatch $record) => static::sellingPrice($record))
                    ->numeric(2)->alignEnd(),
                TextColumn::make('profit_margin')->label('Profit Margin')
                    ->state(fn (ItemBatch $record) => static::profitMargin($record))
                    ->numeric(2)->suffix('%')->alignEnd(),
                TextColumn::make('item.unit.name_ar')->label('Unit')->formatStateUsing(fn (ItemBatch $record) => $record->item?->unit?->name),
                TextColumn::make('balance')->label('Balance')
                    ->formatStateUsing(fn ($state) => BalanceTable::qty($state))
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('balance', $direction)),
            ])
            ->filters([
                Filter::make('has_balance')
                    ->label('Show only items with a balance?')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where(static::balanceSubquery(), '>', 0)),
                Filters::relation('item_id', 'item', 'Item'),
                SelectFilter::make('expiry')
                    ->label('Expiry Status')
                    ->options([
                        'expired' => __('Expired'),
                        'near' => __('Near Expiry'),
                        'valid' => __('Valid'),
                    ])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'expired' => $query->whereDate('expiry_date', '<', today()),
                        'near' => $query->whereDate('expiry_date', '>=', today())->whereDate('expiry_date', '<=', today()->addDays(self::NEAR_EXPIRY_DAYS)),
                        'valid' => $query->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>', today()->addDays(self::NEAR_EXPIRY_DAYS))),
                        default => $query,
                    }),
                Filters::dateRange('expiry_date'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('expiry_date');
    }

    public static function expiryColor(ItemBatch $batch): string
    {
        if (! $batch->expiry_date) {
            return 'gray';
        }
        if ($batch->expiry_date->isPast()) {
            return 'danger';
        }

        return $batch->expiry_date->lte(today()->addDays(self::NEAR_EXPIRY_DAYS)) ? 'warning' : 'success';
    }

    public static function sellingPrice(ItemBatch $batch): float
    {
        return (float) ($batch->sale_price > 0 ? $batch->sale_price : $batch->item?->sale_price);
    }

    /** Profit margin % of the selling price: (price - last purchase price) / price. */
    public static function profitMargin(ItemBatch $batch): ?float
    {
        $price = static::sellingPrice($batch);
        $cost = (float) ($batch->last_purchase_price > 0 ? $batch->last_purchase_price : $batch->item?->purchase_price);

        return $price > 0 ? round(($price - $cost) / $price * 100, 2) : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemBatches::route('/'),
        ];
    }
}
