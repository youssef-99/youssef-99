<?php

namespace App\Filament\Resources\Accounting\Shared;

use App\Enums\AccountType;
use App\Enums\VoucherType;
use App\Filament\Actions\DocumentActions;
use App\Filament\Support\Columns;
use App\Filament\Support\Filters;
use App\Models\Voucher;
use App\Services\VoucherService;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VoucherTable
{
    /** @param  string  $resource  the ErpResource class (for permissions) */
    public static function configure(Table $table, string $resource, VoucherType $type): Table
    {
        $columns = [
            TextColumn::make('number')->label('Voucher No.')->searchable()->sortable(),
            Columns::date(label: 'Voucher Date'),
            TextColumn::make('branch.name_ar')->label('Branch')->formatStateUsing(fn (Voucher $record) => $record->branch?->name)->toggleable(),
            TextColumn::make('account.name_ar')
                ->label($type->headerIsDebit() ? 'Debit Account' : 'Credit Account')
                ->formatStateUsing(fn (Voucher $record) => $record->account ? $record->account->number.' - '.$record->account->name : null),
            Columns::money('amount', 'Amount'),
            TextColumn::make('description')->label('Description')->limit(40)->searchable()->toggleable(),
        ];

        if ($type === VoucherType::PaymentCheque) {
            $columns[] = Columns::name('Bank', 'bank');
            $columns[] = TextColumn::make('cheque_no')->label('Cheque No.')->searchable();
            $columns[] = Columns::date('due_date', 'Due Date');
        }

        if ($type === VoucherType::ReceiptCheque) {
            $columns[] = TextColumn::make('cheque_status')
                ->label('Cheque Status')
                ->badge()
                ->formatStateUsing(fn (?string $state) => AccountType::chequeStatusOptions()[$state] ?? $state);
        }

        $columns[] = TextColumn::make('manual_document_no')->label('Manual Document No.')->toggleable(isToggledHiddenByDefault: true);
        $columns[] = Columns::posted();

        return $table
            ->columns($columns)
            ->filters([
                Filters::year(),
                Filters::branch(),
                Filters::dateRange(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label(__('View Voucher')),
                    EditAction::make(),
                    DocumentActions::post(VoucherService::class, $resource),
                    DocumentActions::unpost(VoucherService::class, $resource),
                    DocumentActions::print('voucher', label: 'Print Voucher'),
                    DeleteAction::make(),
                ])->label(__('Actions')),
            ])
            ->defaultSort('id', 'desc');
    }
}
