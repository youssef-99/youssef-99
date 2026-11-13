<?php

namespace App\Filament\Resources\Inventory\Shared;

use App\Enums\StockDocumentType;
use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Services\StockDocumentService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Shared list for stock documents.
 */
class StockDocumentTable
{
    /**
     * @param  string  $resource  the ErpResource class (for permissions)
     */
    public static function configure(Table $table, string $resource, StockDocumentType $type): Table
    {
        $isTransfer = in_array($type, [StockDocumentType::OutboundTransfer, StockDocumentType::InboundTransfer], true);
        $isInbound = $type === StockDocumentType::InboundTransfer;

        $columns = [
            TextColumn::make('number')->label($type->isStocktake() ? 'Stocktake Record Code' : 'Voucher Code')->searchable()->sortable(),
            Columns::date(label: $type->isStocktake() ? 'Stocktake Record Date' : 'Voucher Date'),
            TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name)->toggleable(),
            TextColumn::make('warehouse.name_ar')->label('Warehouse')->formatStateUsing(fn ($record) => $record->warehouse?->name),
        ];

        if ($isTransfer) {
            array_push($columns,
                TextColumn::make('toBranch.name_ar')->label($isInbound ? 'From Branch' : 'To Branch')->formatStateUsing(fn ($record) => $record->toBranch?->name)->toggleable(),
                TextColumn::make('toWarehouse.name_ar')->label($isInbound ? 'From Warehouse' : 'To Warehouse')->formatStateUsing(fn ($record) => $record->toWarehouse?->name),
            );
        }
        if ($isInbound) {
            $columns[] = TextColumn::make('relatedDocument.number')->label('Outbound Transfer No.');
        }
        if (in_array($type, [StockDocumentType::PositiveAdjustment, StockDocumentType::NegativeAdjustment, StockDocumentType::AnnualStocktake], true)) {
            $columns[] = TextColumn::make('account.name_ar')->label('Account')->formatStateUsing(fn ($record) => $record->account ? $record->account->number.' - '.$record->account->name : null)->toggleable();
        }
        if ($type === StockDocumentType::PeriodicStocktake) {
            $columns[] = TextColumn::make('supplier.name_ar')->label('Supplier')->formatStateUsing(fn ($record) => $record->supplier?->name)->toggleable();
        }

        array_push($columns,
            Columns::money('gross_total', 'Gross Price')->toggleable(),
            Columns::money('net_total', 'Net'),
            TextColumn::make('notes')->label('Notes')->limit(40)->toggleable(isToggledHiddenByDefault: true),
        );
        if ($type === StockDocumentType::PeriodicStocktake) {
            $columns[] = Columns::boolean('is_approved', 'Approved?');
        }
        $columns[] = Columns::posted();

        return $table
            ->columns($columns)
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filters::relation('warehouse_id', 'warehouse', 'Warehouse'),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make(array_values(array_filter([
                    ViewAction::make(),
                    EditAction::make(),
                    $type === StockDocumentType::PeriodicStocktake ? null : DocumentActions::post(StockDocumentService::class, $resource, $isInbound ? 'Receive' : 'Post'),
                    DocumentActions::unpost(StockDocumentService::class, $resource),
                    DocumentActions::print('stock'),
                    $isInbound ? null : DeleteAction::make(),
                ])))->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }
}
