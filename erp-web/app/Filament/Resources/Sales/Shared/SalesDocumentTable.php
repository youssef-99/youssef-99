<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Services\SalesDocumentService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SalesDocumentTable
{
    /**
     * @param  string  $resource  the ErpResource class (for permissions)
     * @param  array  $extraColumns  columns appended before the totals
     */
    public static function configure(Table $table, string $resource, array $extraColumns = []): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Invoice No.')->searchable()->sortable(),
                Columns::date(label: 'Date'),
                TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn ($record) => $record->branch?->name)->toggleable(),
                TextColumn::make('customer.name_ar')->label('Account')->formatStateUsing(fn ($record) => $record->customer?->name ?? $record->account?->name)->searchable(),
                Columns::date('due_date', 'Due Date')->toggleable(),
                ...$extraColumns,
                Columns::money('gross_total', 'Gross Price')->toggleable(),
                Columns::money('net_total', 'Net Price'),
                Columns::money('tax_total', 'Tax'),
                Columns::money('grand_total', 'Net After Tax'),
                TextColumn::make('manual_no')->label('Manual Voucher No.')->toggleable(isToggledHiddenByDefault: true),
                Columns::posted(),
            ])
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filters::relation('customer_id', 'customer', 'Customer'),
                Filters::relation('salesman_id', 'salesman', 'Salesman'),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DocumentActions::post(SalesDocumentService::class, $resource),
                    DocumentActions::unpost(SalesDocumentService::class, $resource),
                    DocumentActions::print('sales'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }
}
