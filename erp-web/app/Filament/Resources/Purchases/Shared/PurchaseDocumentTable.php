<?php

namespace App\Filament\Resources\Purchases\Shared;

use App\Enums\PurchaseDocumentType;
use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * List table for purchase documents (purchases module and cash purchases).
 */
class PurchaseDocumentTable
{
    /**
     * @param  string  $resource  the ErpResource class (for permissions)
     * @param  array<int, Action>  $extraActions  row actions added after Print
     */
    public static function configure(Table $table, string $resource, PurchaseDocumentType $type, array $extraActions = []): Table
    {
        $isOrder = $type === PurchaseDocumentType::PurchaseOrder;
        $isForeign = $type === PurchaseDocumentType::ForeignPurchaseInvoice;
        $isInvoice = ! $isOrder && ! $type->isReturn();

        $columns = [
            TextColumn::make('number')->label($isOrder ? 'Purchase Order No.' : ($type->isReturn() ? 'Return No.' : 'Invoice No.'))->searchable()->sortable(),
            Columns::date(label: $isOrder ? 'Purchase Order Date' : 'Invoice Date'),
            TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (PurchaseDocument $record) => $record->branch?->name)->toggleable(),
            TextColumn::make('account.name_ar')->label('Account')
                ->formatStateUsing(fn (PurchaseDocument $record) => $record->supplier?->name ?? $record->cashSupplier?->name ?? $record->account?->name)
                ->searchable(),
        ];

        if (! $isOrder) {
            $columns[] = TextColumn::make('supplier_invoice_no')->label($type->isCash() ? 'Cash Supplier Invoice No.' : 'Supplier Invoice No.')->searchable()->toggleable();
        }
        if ($type->isReturn()) {
            $columns[] = TextColumn::make('originalDocument.number')->label('Original Invoice No.')->toggleable();
        }
        if ($isInvoice && ! $type->isCash()) {
            $columns[] = Columns::date('due_date', 'Due Date')->toggleable();
        }
        if ($isForeign) {
            $columns[] = TextColumn::make('currency.name_ar')->label('Currency')->formatStateUsing(fn (PurchaseDocument $record) => $record->currency?->name);
            $columns[] = Columns::money('shipping_cost', 'Shipping Cost')->toggleable();
            $columns[] = Columns::money('purchase_expenses', 'Purchase Expenses')->toggleable();
        }

        array_push($columns,
            Columns::money('gross_total', 'Gross Price')->toggleable(),
            Columns::money('net_total', 'Net Price'),
            Columns::money('tax_total', 'Tax'),
            Columns::money('grand_total', 'Net After Tax'),
        );

        if ($isOrder) {
            $columns[] = TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (string $state) => __(Str::headline($state)))
                ->color(fn (string $state) => $state === 'closed' ? 'success' : 'gray');
        } else {
            $columns[] = Columns::posted();
        }

        if ($isInvoice && ! $type->isCash()) {
            $columns[] = TextColumn::make('rasd_status')->label('Rasd Status')->badge()
                ->formatStateUsing(fn (?string $state) => $state ? __(Str::headline($state)) : null)
                ->color(fn (?string $state) => match ($state) {
                    'sent' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                })
                ->toggleable();
        }

        $filters = [Filters::year(), Filters::branch()];
        if (! $type->isCash()) {
            $filters[] = Filters::relation('supplier_id', 'supplier', 'Supplier');
        }
        $filters[] = Filters::dateRange();

        $actions = [ViewAction::make(), EditAction::make()];
        if ($type->isPostable()) {
            $actions[] = DocumentActions::post(PurchaseDocumentService::class, $resource);
            $actions[] = DocumentActions::unpost(PurchaseDocumentService::class, $resource);
        }
        $actions[] = DocumentActions::print($resource::getPrintType(), label: $isOrder ? 'Print' : 'Print Invoice');
        array_push($actions, ...$extraActions);
        $actions[] = DeleteAction::make();

        return $table
            ->columns($columns)
            ->filters($filters)
            ->recordActions([ActionGroup::make($actions)->label(__('Actions'))])
            ->defaultSort('id', 'desc');
    }
}
