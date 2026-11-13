<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemBalanceLimitResource\Pages\ManageItemBalanceLimits;
use App\Filament\Resources\Inventory\Support\BalanceTable;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\Agent;
use App\Models\ItemBalanceLimit;
use App\Services\StockService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Item balance limits (حدود أرصدة الأصناف) — /inventory/item-balance-limits.
 * Minimum / maximum / reorder quantity per item and branch (optionally per warehouse).
 */
class ItemBalanceLimitResource extends ErpResource
{
    protected static ?string $model = ItemBalanceLimit::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.item-balance-limits';

    protected static ?string $modelLabel = 'Item Balance Limit';

    protected static ?string $pluralModelLabel = 'Item Balance Limits';

    protected static ?string $slug = 'inventory/item-balance-limits';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-vertical';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Fields::relation('item_id', 'item', 'Item', search: ['code', 'name_ar', 'name_en', 'barcode'])->required()
                ->unique(ItemBalanceLimit::class, 'item_id', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('branch_id', $get('branch_id'))
                    ->where('warehouse_id', $get('warehouse_id')))
                ->validationMessages(['unique' => __('A limit for this item, branch and warehouse already exists.')]),
            Fields::branch(),
            Fields::warehouse()->required(false),
            Fields::money('min_qty', 'Minimum Quantity')->minValue(0)->required(),
            Fields::money('max_qty', 'Maximum Quantity')->minValue(0)->required()->gte('min_qty'),
            Fields::money('reorder_qty', 'Reorder Quantity')->minValue(0)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.code')->label('Item Code')->searchable()->sortable(),
                Columns::name('Item Name', 'item'),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name),
                TextColumn::make('warehouse.name_ar')->label('Warehouse')->formatStateUsing(fn ($record) => $record->warehouse?->name)->placeholder(__('All Warehouses')),
                TextColumn::make('min_qty')->label('Minimum Quantity')->numeric(3)->alignEnd(),
                TextColumn::make('max_qty')->label('Maximum Quantity')->numeric(3)->alignEnd(),
                TextColumn::make('reorder_qty')->label('Reorder Quantity')->numeric(3)->alignEnd(),
                TextColumn::make('current_balance')->label('Balance')
                    ->state(fn (ItemBalanceLimit $record) => StockService::balance($record->item_id, $record->warehouse_id, null, $record->warehouse_id ? null : $record->branch_id))
                    ->formatStateUsing(fn ($state) => BalanceTable::qty($state))
                    ->badge()
                    ->color(fn (ItemBalanceLimit $record, $state) => match (true) {
                        (float) $state <= (float) $record->min_qty => 'danger',
                        (float) $record->max_qty > 0 && (float) $state > (float) $record->max_qty => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                Filters::branch(),
                SelectFilter::make('agent')
                    ->label('Agent')
                    ->options(fn () => Agent::query()->orderBy('code')->get()->mapWithKeys(fn (Agent $a) => [$a->id => $a->label]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $agent) => $q->whereHas('item', fn ($i) => $i->where('agent_id', $agent))))
                    ->searchable(),
                Filters::relation('item_id', 'item', 'Item'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemBalanceLimits::route('/'),
        ];
    }
}
