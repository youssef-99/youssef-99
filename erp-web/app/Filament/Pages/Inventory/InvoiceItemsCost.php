<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\Module;
use App\Enums\Permission;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\HasErpPermission;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Branch;
use App\Models\SalesDocumentLine;
use App\Services\InvoiceCostService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invoice items cost (تكلفة أصناف الفواتير) — /inventory/invoice-items-cost.
 * Posted sales lines with their cost; Update Cost re-costs them from the moving-average ledger and
 * Post Invoice Entries regenerates the COGS / inventory journal lines.
 */
class InvoiceItemsCost extends Page implements HasTable
{
    /** @var array<Permission> */
    protected static array $pagePermissions = [Permission::Post];

    use HasErpPermission;
    use InteractsWithTable;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.invoice-items-cost';

    protected static ?string $title = 'Invoice Items Cost';

    protected static ?string $slug = 'inventory/invoice-items-cost';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?int $navigationSort = 170;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('updateCost')->visible(fn (): bool => static::userCan(Permission::Post))
                ->label('Update Cost')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema(static::periodSchema())
                ->modalDescription(__('Recomputes the cost of every posted sales line in the period from the moving-average stock ledger.'))
                ->action(function (array $data) {
                    $result = InvoiceCostService::updateCosts($data['from'], $data['to'], $data['branch_id'] ?? null);
                    Notification::make()->success()
                        ->title(__('Cost updated'))
                        ->body(__(':lines lines in :documents invoices re-costed (:items items).', $result))
                        ->send();
                }),
            Action::make('postEntries')->visible(fn (): bool => static::userCan(Permission::Post))
                ->label('Post Invoice Entries')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->schema(static::periodSchema())
                ->modalDescription(__('Regenerates the cost of goods sold / inventory lines of the invoices\' journal entries from their current cost.'))
                ->action(function (array $data) {
                    $count = InvoiceCostService::postEntries($data['from'], $data['to'], $data['branch_id'] ?? null);
                    Notification::make()->success()->title(__(':count invoice entries posted', ['count' => $count]))->send();
                }),
        ];
    }

    /** @return array<int, Component> */
    public static function periodSchema(): array
    {
        return [
            DatePicker::make('from')->label('From Date')->native(false)->default(now()->startOfMonth())->required(),
            DatePicker::make('to')->label('To Date')->native(false)->default(now())->required()->afterOrEqual('from'),
            Select::make('branch_id')->label('Branch')->options(fn () => Branch::query()->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(InvoiceCostService::linesQuery()->with(['salesDocument.branch', 'item']))
            ->columns([
                TextColumn::make('salesDocument.number')->label('Invoice No.')->searchable(),
                TextColumn::make('salesDocument.date')->label('Invoice Date')->date('d/m/Y'),
                TextColumn::make('salesDocument.type')->label('Document Type')
                    ->formatStateUsing(fn (?string $state) => SalesDocumentType::tryFrom((string) $state)?->label() ?? $state),
                TextColumn::make('salesDocument.branch.name_ar')->label('Branch')->formatStateUsing(fn (SalesDocumentLine $record) => $record->salesDocument?->branch?->name)->toggleable(),
                TextColumn::make('item.code')->label('Item Code')->searchable(),
                Columns::name('Item Name', 'item'),
                TextColumn::make('quantity')->label('Quantity')->numeric(3)->alignEnd(),
                Columns::money('unit_price', 'Unit Price'),
                TextColumn::make('unit_cost')->label('Unit Cost')->numeric(4)->alignEnd()->sortable(),
                TextColumn::make('total_cost')->label('Total Cost')
                    ->state(fn (SalesDocumentLine $record) => round(((float) $record->quantity + (float) $record->free_qty) * (float) $record->unit_cost, 2))
                    ->numeric(2)->alignEnd(),
                Columns::money('net', 'Item Net'),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Period')
                    ->schema([
                        DatePicker::make('from')->label('From Date')->native(false),
                        DatePicker::make('to')->label('To Date')->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query->whereHas('salesDocument', fn (Builder $q) => $q
                        ->when($data['from'] ?? null, fn ($w, $d) => $w->whereDate('date', '>=', $d))
                        ->when($data['to'] ?? null, fn ($w, $d) => $w->whereDate('date', '<=', $d)))),
                SelectFilter::make('branch')
                    ->label('Branch')
                    ->options(fn () => Branch::query()->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name]))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $branch) => $q->whereHas('salesDocument', fn ($d) => $d->where('branch_id', $branch)))),
                SelectFilter::make('type')
                    ->label('Document Type')
                    ->options(fn () => InvoiceCostService::typeOptions()->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $type) => $q->whereHas('salesDocument', fn ($d) => $d->where('type', $type)))),
                Filters::relation('item_id', 'item', 'Item'),
                Filter::make('zero_cost')->label('Lines without cost')->toggle()->query(fn (Builder $query) => $query->where('unit_cost', '<=', 0)),
            ])
            ->defaultSort('id', 'desc');
    }
}
