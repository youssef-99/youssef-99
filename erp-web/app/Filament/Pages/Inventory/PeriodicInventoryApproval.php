<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\Module;
use App\Enums\Permission;
use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Concerns\HasErpPermission;
use App\Filament\Resources\Inventory\PeriodicStocktakeResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Periodic stocktake approval (اعتماد الجرد الدوري) — /inventory/periodic-inventory-approval.
 * Lists unapproved periodic stocktake records; Approve marks them approved and posts them.
 */
class PeriodicInventoryApproval extends Page implements HasTable
{
    use HasErpPermission;
    use InteractsWithTable;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.periodic-inventory-approval';

    protected static ?string $title = 'Periodic Stocktake Approval';

    protected static ?string $slug = 'inventory/periodic-inventory-approval';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static ?int $navigationSort = 160;

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public static function canApprove(): bool
    {
        return PeriodicStocktakeResource::userCan(Permission::Post);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(StockDocument::query()
                ->where('type', StockDocumentType::PeriodicStocktake->value)
                ->where('is_approved', false)
                ->where('is_posted', false)
                ->with(['branch', 'warehouse', 'supplier']))
            ->columns([
                TextColumn::make('number')->label('Stocktake Record Code')->searchable()->sortable(),
                Columns::date(label: 'Stocktake Record Date'),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name),
                TextColumn::make('warehouse.name_ar')->label('Warehouse')->formatStateUsing(fn ($record) => $record->warehouse?->name),
                TextColumn::make('supplier.name_ar')->label('Supplier')->formatStateUsing(fn ($record) => $record->supplier?->name),
                Columns::money('net_total', 'Net'),
                TextColumn::make('notes')->label('Notes')->limit(40),
            ])
            ->filters([
                Filters::branch(),
                Filters::relation('warehouse_id', 'warehouse', 'Warehouse'),
                Filters::dateRange(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->url(fn (StockDocument $record) => PeriodicStocktakeResource::getUrl('view', ['record' => $record])),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('The stocktake record will be approved and posted.'))
                    ->visible(fn () => static::canApprove())
                    ->action(function (StockDocument $record, Action $action) {
                        try {
                            StockDocumentService::approve($record);
                            Notification::make()->success()->title(__('Approved and posted'))->send();
                        } catch (ErpException $e) {
                            Notification::make()->danger()->title(__('Cannot approve'))->body($e->getMessage())->persistent()->send();
                            $action->halt();
                        }
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')
                    ->label('Approve')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn () => static::canApprove())
                    ->action(function (Collection $records) {
                        $approved = 0;
                        $errors = [];
                        foreach ($records as $record) {
                            try {
                                StockDocumentService::approve($record);
                                $approved++;
                            } catch (ErpException $e) {
                                $errors[] = "#{$record->number}: {$e->getMessage()}";
                            }
                        }
                        Notification::make()
                            ->title(__(':count stocktake records approved', ['count' => $approved]))
                            ->body(implode("\n", $errors))
                            ->color($errors ? 'warning' : 'success')
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('date', 'desc');
    }
}
