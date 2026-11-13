<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\ItemType;
use App\Enums\Module;
use App\Enums\Permission;
use App\Filament\Concerns\HasErpPermission;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Agent;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemGroup;
use App\Models\Supplier;
use App\Services\ItemActionsService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Item actions (إجراءات على الأصناف) — /inventory/actions-for-items.
 * Bulk tools applied to a scope (category / agent / supplier / group) from the header, or to the selected rows.
 */
class ActionsForItems extends Page implements HasTable
{
    /** @var array<Permission> */
    protected static array $pagePermissions = [Permission::Edit];

    use HasErpPermission;
    use InteractsWithTable;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.actions-for-items';

    protected static ?string $title = 'Item Actions';

    protected static ?string $slug = 'inventory/actions-for-items';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 180;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updatePurchasePrice')->visible(fn (): bool => static::userCan(Permission::Edit))
                ->label('Update Purchase Price')
                ->icon('heroicon-o-arrow-path')
                ->modalDescription(__('Sets each item\'s purchase price to the price of its last posted purchase.'))
                ->schema(static::scopeSchema())
                ->action(fn (array $data) => static::notifyCount(ItemActionsService::updatePurchasePriceFromLastPurchase(ItemActionsService::scope($data)))),
            Action::make('updateSalePrice')->visible(fn (): bool => static::userCan(Permission::Edit))
                ->label('Update Selling Price by %')
                ->icon('heroicon-o-receipt-percent')
                ->schema([
                    ...static::scopeSchema(),
                    Grid::make(2)->schema([
                        TextInput::make('percent')->label('Percentage')->numeric()->required()->minValue(-90)->maxValue(1000)->suffix('%')
                            ->helperText(__('Use a negative value to lower prices.')),
                        Toggle::make('include_batches')->label('Include batch selling prices?')->default(true)->inline(false),
                    ]),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => static::notifyCount(ItemActionsService::updateSalePriceByPercent(ItemActionsService::scope($data), (float) $data['percent'], (bool) ($data['include_batches'] ?? true)))),
            Action::make('setActive')->visible(fn (): bool => static::userCan(Permission::Edit))
                ->label('Activate / Deactivate')
                ->icon('heroicon-o-power')
                ->schema([
                    ...static::scopeSchema(),
                    Toggle::make('active')->label('Active?')->default(true),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => static::notifyCount(ItemActionsService::setActive(ItemActionsService::scope($data), (bool) $data['active']))),
            Action::make('sendToEpos')->visible(fn (): bool => static::userCan(Permission::Edit))
                ->label('Send Items to e-POS')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->modalDescription(__('Exports the items to a file for the e-POS and marks them as sent.'))
                ->schema([
                    ...static::scopeSchema(),
                    Grid::make(2)->schema([
                        Select::make('format')->label('File Format')->options(['xlsx' => 'Excel (XLSX)', 'csv' => 'CSV'])->default('xlsx')->required(),
                        Toggle::make('only_unsent')->label('Only items not sent yet?')->default(false)->inline(false),
                    ]),
                ])
                ->action(function (array $data): BinaryFileResponse {
                    $query = ItemActionsService::scope($data)->where('is_active', true)
                        ->when($data['only_unsent'] ?? false, fn (Builder $q) => $q->where('sent_to_epos', false));

                    return static::download(ItemActionsService::exportToEpos($query, $data['format'] ?? 'xlsx'));
                }),
            Action::make('recalculateBatches')->visible(fn (): bool => static::userCan(Permission::Edit))
                ->label('Recalculate Batch Balances')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->modalDescription(__('Rebuilds the average cost from the stock ledger and refreshes each batch\'s last purchase price.'))
                ->schema(static::scopeSchema())
                ->action(function (array $data) {
                    $result = ItemActionsService::recalculateBatchBalances(ItemActionsService::scope($data));
                    Notification::make()
                        ->title(__(':items items and :batches batches recalculated', $result))
                        ->body($result['negative_batches'] ? __(':count batches have a negative balance.', ['count' => $result['negative_batches']]) : null)
                        ->color($result['negative_batches'] ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }

    /** @return array<int, Component> */
    public static function scopeSchema(): array
    {
        return [
            Grid::make(2)->schema([
                Select::make('category_id')->label('Category')->searchable()
                    ->options(fn () => ItemCategory::query()->orderBy('code')->get()->mapWithKeys(fn (ItemCategory $c) => [$c->id => $c->label])),
                Select::make('agent_id')->label('Agent')->searchable()
                    ->options(fn () => Agent::query()->orderBy('code')->get()->mapWithKeys(fn (Agent $a) => [$a->id => $a->label])),
                Select::make('supplier_id')->label('Supplier')->searchable()
                    ->options(fn () => Supplier::query()->orderBy('code')->limit(500)->get()->mapWithKeys(fn (Supplier $s) => [$s->id => $s->label])),
                Select::make('group_id')->label('Group')->searchable()
                    ->options(fn () => ItemGroup::query()->orderBy('code')->get()->mapWithKeys(fn (ItemGroup $g) => [$g->id => $g->label])),
            ]),
        ];
    }

    public static function notifyCount(int $count): void
    {
        Notification::make()->success()->title(__(':count items updated', ['count' => $count]))->send();
    }

    /** @param array{path: string, count: int} $export */
    public static function download(array $export): BinaryFileResponse
    {
        Notification::make()->success()->title(__(':count items sent to e-POS', ['count' => $export['count']]))->send();

        return response()->download($export['path'], basename($export['path']))->deleteFileAfterSend();
    }

    public function table(Table $table): Table
    {
        $selected = fn (Collection $records): Builder => Item::query()->whereKey($records->modelKeys());

        return $table
            ->query(Item::query()->with(['category', 'agent']))
            ->columns([
                Columns::code(label: 'Item Code'),
                TextColumn::make('barcode')->label('Barcode')->searchable(),
                Columns::name('Item Name'),
                TextColumn::make('category.name_ar')->label('Category')->formatStateUsing(fn (Item $record) => $record->category?->name),
                TextColumn::make('agent.name_ar')->label('Agent')->formatStateUsing(fn (Item $record) => $record->agent?->name)->toggleable(),
                Columns::money('purchase_price', 'Purchase Price'),
                Columns::money('sale_price', 'Selling Price'),
                TextColumn::make('average_cost')->label('Average Cost')->numeric(4)->alignEnd()->toggleable(),
                IconColumn::make('sent_to_epos')->label('Sent to e-POS?')->boolean(),
                Columns::boolean(),
            ])
            ->filters([
                Filters::relation('category_id', 'category', 'Category'),
                Filters::relation('agent_id', 'agent', 'Agent'),
                Filters::relation('supplier_id', 'supplier', 'Supplier'),
                SelectFilter::make('item_type')->label('Item Type')->options(ItemType::options()),
                TernaryFilter::make('is_active')->label('Active?'),
                TernaryFilter::make('sent_to_epos')->label('Sent to e-POS?'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activateSelected')->visible(fn (): bool => static::userCan(Permission::Edit))->label('Activate')->icon('heroicon-o-check-circle')
                        ->action(fn (Collection $records) => static::notifyCount(ItemActionsService::setActive($selected($records), true)))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('deactivateSelected')->visible(fn (): bool => static::userCan(Permission::Edit))->label('Deactivate')->icon('heroicon-o-x-circle')->color('danger')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => static::notifyCount(ItemActionsService::setActive($selected($records), false)))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('updatePurchasePriceSelected')->visible(fn (): bool => static::userCan(Permission::Edit))->label('Update Purchase Price')->icon('heroicon-o-arrow-path')
                        ->action(fn (Collection $records) => static::notifyCount(ItemActionsService::updatePurchasePriceFromLastPurchase($selected($records))))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('sendSelectedToEpos')->visible(fn (): bool => static::userCan(Permission::Edit))->label('Send Items to e-POS')->icon('heroicon-o-paper-airplane')
                        ->action(fn (Collection $records): BinaryFileResponse => static::download(ItemActionsService::exportToEpos($selected($records))))
                        ->deselectRecordsAfterCompletion(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('code');
    }
}
