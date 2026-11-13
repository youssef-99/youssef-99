<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Enums\SalesDocumentType;
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

class SalesDocumentTable
{
    /**
     * @param  string  $resource  the ErpResource class (for permissions)
     * @param  array  $extraColumns  columns appended before the totals
     * @param  array  $extraActions  row actions added to the actions menu (before Delete)
     * @param  ?SalesDocumentType  $statusType  show the workflow status column (quotations, customer orders)
     */
    public static function configure(
        Table $table,
        string $resource,
        array $extraColumns = [],
        array $extraActions = [],
        string $numberLabel = 'Invoice No.',
        string $postLabel = 'Post',
        ?SalesDocumentType $statusType = null,
    ): Table {
        $columns = [
            TextColumn::make('number')->label($numberLabel)->searchable()->sortable(),
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
        ];

        $columns[] = $statusType
            ? TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (?string $state) => SalesDocumentStatus::label($state, $statusType))
                ->color(fn (?string $state) => SalesDocumentStatus::color($state))
            : Columns::posted();

        return $table
            ->columns($columns)
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
                    DocumentActions::post(SalesDocumentService::class, $resource, $postLabel),
                    DocumentActions::unpost(SalesDocumentService::class, $resource),
                    DocumentActions::print('sales'),
                    ...$extraActions,
                    DeleteAction::make()->visible(fn (SalesDocument $record) => ! $record->is_posted),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }
}
