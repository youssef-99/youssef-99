<?php

namespace App\Filament\Resources\Cash\Shared;

use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lists of cash sales invoices / returns: Invoice No., Invoice Date, Branch, Account, Gross Price,
 * Net Price, Tax, Net After Tax.
 */
class CashSalesDocumentTable
{
    public static function configure(Table $table, string $resource, bool $isReturn = false): Table
    {
        $columns = [
            TextColumn::make('number')->label($isReturn ? 'Return No.' : 'Invoice No.')->searchable()->sortable(),
            Columns::date(label: $isReturn ? 'Return Date' : 'Invoice Date'),
            TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (SalesDocument $record) => $record->branch?->name)->toggleable(),
            TextColumn::make('account.name_ar')->label('Account')->formatStateUsing(fn (SalesDocument $record) => $record->account?->name),
            TextColumn::make('cashCustomer.name_ar')->label('Cash Customer')->formatStateUsing(fn (SalesDocument $record) => $record->cashCustomer?->name)->searchable()->toggleable(),
        ];

        if ($isReturn) {
            $columns[] = TextColumn::make('originalDocument.number')->label('Original Sales Invoice');
        }

        array_push($columns,
            TextColumn::make('manual_no')->label('Manual Invoice No.')->toggleable(isToggledHiddenByDefault: true),
            Columns::money('gross_total', 'Gross Price'),
            Columns::money('net_total', 'Net Price'),
            Columns::money('tax_total', 'Tax'),
            Columns::money('grand_total', 'Net After Tax'),
            Columns::posted(),
        );

        return $table
            ->columns($columns)
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filters::relation('cash_customer_id', 'cashCustomer', 'Cash Customer'),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DocumentActions::post(SalesDocumentService::class, $resource),
                    DocumentActions::unpost(SalesDocumentService::class, $resource),
                    DocumentActions::print($resource::getPrintType(), label: 'Print Invoice'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }
}
