<?php

namespace App\Filament\Resources\Accounting\FinancingContractResource\RelationManagers;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Resources\Accounting\FinancingContractResource;
use App\Filament\Support\Columns;
use App\Filament\Support\Fields;
use App\Models\FinancingInstallment;
use App\Services\FinancingContractService;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Installment schedule (جدول الأقساط) of a financing contract, with Pay / Cancel Payment.
 */
class InstallmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'installments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Installments');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('Installment No.')->alignCenter(),
                Columns::date('due_date', 'Due Date'),
                Columns::money('amount', 'Installment Amount')->summarize(Sum::make()->label(__('Total'))->numeric(2)),
                Columns::boolean('is_paid', 'Paid?'),
                Columns::date('paid_at', 'Payment Date'),
                TextColumn::make('paymentAccount.name_ar')->label('Payment Account')->formatStateUsing(fn (FinancingInstallment $record) => $record->paymentAccount?->label),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Pay Installment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (FinancingInstallment $record): bool => ! $record->is_paid && $this->getOwnerRecord()->is_posted && FinancingContractResource::userCan(Permission::Post))
                    ->schema([
                        Select::make('payment_account_id')
                            ->label('Payment Account')
                            ->options(fn (): array => Fields::accountOptions())
                            ->default(fn (): ?int => Settings::account('cash'))
                            ->searchable()
                            ->required(),
                        Fields::date('paid_at', 'Payment Date'),
                    ])
                    ->action(function (FinancingInstallment $record, array $data, Action $action): void {
                        try {
                            FinancingContractService::payInstallment($record, (int) $data['payment_account_id'], $data['paid_at']);
                            Notification::make()->success()->title(__('Installment paid'))->send();
                        } catch (ErpException $e) {
                            Notification::make()->danger()->title(__('Cannot pay'))->body($e->getMessage())->persistent()->send();
                            $action->halt();
                        }
                    }),
                Action::make('cancelPayment')
                    ->label('Cancel Payment')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (FinancingInstallment $record): bool => $record->is_paid && FinancingContractResource::userCan(Permission::Post))
                    ->action(function (FinancingInstallment $record): void {
                        FinancingContractService::cancelInstallmentPayment($record);
                        Notification::make()->success()->title(__('Payment cancelled'))->send();
                    }),
            ])
            ->defaultSort('sequence')
            ->paginated(false);
    }
}
