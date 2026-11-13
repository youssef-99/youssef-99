<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\ItemSerialNumberResource\Pages\ManageItemSerialNumbers;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Filament\Support\Filters;
use App\Models\ItemBatch;
use App\Models\ItemSerialNumber;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Item serial numbers (الأرقام التسلسلية للأصناف) — /inventory/item-serial-numbers.
 */
class ItemSerialNumberResource extends ErpResource
{
    public const STATUSES = ['in_stock' => 'In Stock', 'sold' => 'Sold', 'returned' => 'Returned'];

    protected static ?string $model = ItemSerialNumber::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.item-serial-numbers';

    protected static ?string $modelLabel = 'Serial Number';

    protected static ?string $pluralModelLabel = 'Item Serial Numbers';

    protected static ?string $slug = 'inventory/item-serial-numbers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Fields::relation('item_id', 'item', 'Item', search: ['code', 'name_ar', 'name_en', 'barcode'])->required()->live(),
            TextInput::make('serial_no')->label('Serial No.')->required()->maxLength(100)
                ->unique(ItemSerialNumber::class, 'serial_no', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('item_id', $get('item_id'))),
            Select::make('item_batch_id')->label('Batch No.')
                ->options(fn (Get $get) => $get('item_id') ? ItemBatch::query()->where('item_id', $get('item_id'))->pluck('batch_no', 'id')->all() : []),
            Fields::relation('warehouse_id', 'warehouse', 'Warehouse'),
            Select::make('status')->label('Status')->options(static::statusOptions())->default('in_stock')->required(),
        ]);
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return array_map(fn (string $label) => __($label), self::STATUSES);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item.code')->label('Item Code')->searchable()->sortable(),
                Columns::name('Item Name', 'item'),
                TextColumn::make('serial_no')->label('Serial No.')->searchable()->sortable(),
                TextColumn::make('itemBatch.batch_no')->label('Batch No.')->searchable(),
                TextColumn::make('warehouse.name_ar')->label('Warehouse')->formatStateUsing(fn ($record) => $record->warehouse?->name),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => static::statusOptions()[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        'in_stock' => 'success',
                        'sold' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                Filters::relation('item_id', 'item', 'Item'),
                Filter::make('batch_no')
                    ->label('Batch No.')
                    ->schema([TextInput::make('batch_no')->label('Batch No.')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['batch_no'] ?? null, fn ($q, $batch) => $q->whereHas('itemBatch', fn ($b) => $b->where('batch_no', 'like', "%{$batch}%")))),
                SelectFilter::make('status')->label('Status')->options(static::statusOptions()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageItemSerialNumbers::route('/'),
        ];
    }
}
