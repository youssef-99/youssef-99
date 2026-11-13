<?php

namespace App\Filament\Resources\Accounting\Shared;

use App\Enums\AccountType;
use App\Enums\VoucherType;
use App\Filament\Support\Fields;
use App\Models\Bank;
use App\Services\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Shared form for cash box vouchers (سندات الصندوق): receipt, cash/cheque payment, credit/debit notices
 * and cheque receipt vouchers. The header account is the cash/bank side; lines are the counter accounts.
 * The header amount must equal the lines total.
 */
class VoucherForm
{
    public static function configure(Schema $schema, VoucherType $type): Schema
    {
        $headerIsDebit = $type->headerIsDebit();
        $isReceipt = in_array($type, [VoucherType::ReceiptCash, VoucherType::ReceiptCheque], true);
        $isPayment = in_array($type, [VoucherType::PaymentCash, VoucherType::PaymentCheque], true);

        $header = [
            Fields::branch(),
            Fields::number(label: 'Voucher No.'),
            Fields::date(label: 'Voucher Date'),
        ];

        if ($isReceipt) {
            $header[] = TextInput::make('received_by')->label('Received By')->maxLength(255);
            $header[] = TextInput::make('manual_document_no')->label('Manual Receipt Document No.')->maxLength(50);
        }

        if ($isPayment) {
            $header[] = TextInput::make('received_by')->label('Received By (Payee)')->maxLength(255);
            $header[] = TextInput::make('pay_to')->label($type === VoucherType::PaymentCheque ? 'Cheque Payable To' : 'Pay to the Order Of')->maxLength(255);
            $header[] = TextInput::make('manual_document_no')->label('Manual Payment Document No.')->maxLength(50);
        }

        if ($type === VoucherType::PaymentCheque) {
            $header[] = AccountingFields::bank()
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set): void {
                    if ($accountId = $state ? Bank::query()->whereKey($state)->value('account_id') : null) {
                        $set('account_id', $accountId);
                    }
                });
            $header[] = TextInput::make('cheque_no')->label('Cheque No.')->maxLength(50);
            $header[] = DatePicker::make('due_date')->label('Due Date')->native(false)->displayFormat('d/m/Y')->required()->default(now());
        }

        if ($type === VoucherType::ReceiptCheque) {
            $header[] = Select::make('cheque_status')
                ->label('Cheque Status')
                ->options(AccountType::chequeStatusOptions())
                ->default('under_collection')
                ->disabled()
                ->dehydrated(false);
        }

        $header[] = Fields::account(label: $headerIsDebit ? 'Debit Account' : 'Credit Account')
            ->required()
            ->default(fn (): ?int => match ($type) {
                VoucherType::ReceiptCheque => Settings::account('cheques_under_collection'),
                VoucherType::ReceiptCash, VoucherType::PaymentCash => Settings::account('cash'),
                default => null,
            });
        $header[] = TextInput::make('amount')
            ->label($headerIsDebit ? 'Debit Amount' : 'Credit Amount')
            ->numeric()
            ->required()
            ->minValue(0.01)
            ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                $total = static::linesTotal($get('lines') ?? []);
                if (abs(round((float) $value, 2) - $total) > 0.009) {
                    $fail(__('The amount must equal the lines total (:total).', ['total' => number_format($total, 2)]));
                }
            });
        $header[] = Textarea::make('description')->label('Description')->required()->rows(1)->columnSpanFull();

        return $schema->columns(1)->components([
            Section::make()->columns(['default' => 1, 'md' => 2, 'xl' => 4])->schema($header),
            static::linesRepeater($type),
            Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                TextInput::make('lines_total')
                    ->label($headerIsDebit ? 'Total Credit' : 'Total Debit')
                    ->numeric()
                    ->readOnly()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(static::linesTotal($get('lines') ?? []))),
                Toggle::make('post_now')->label('Post Voucher?')->dehydrated(false)->default(false)->visibleOn(['create', 'edit']),
            ]),
        ]);
    }

    public static function linesRepeater(VoucherType $type): Repeater
    {
        $isCheque = $type === VoucherType::ReceiptCheque;
        $amountLabel = $type->headerIsDebit() ? 'Credit Amount' : 'Debit Amount';

        $columns = [
            TableColumn::make(__('Account Name'))->markAsRequired(),
            TableColumn::make(__('Account Type')),
            TableColumn::make(__($amountLabel))->markAsRequired(),
        ];
        $schema = [
            AccountingFields::lineAccount(),
            AccountingFields::accountType(),
            TextInput::make('amount')
                ->label($amountLabel)
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotal($get, $set, '../../')),
        ];

        if ($isCheque) {
            array_push($columns,
                TableColumn::make(__('Bank')),
                TableColumn::make(__('Due Date'))->markAsRequired(),
                TableColumn::make(__('Cheque No.')),
            );
            array_push($schema,
                AccountingFields::bank(),
                DatePicker::make('due_date')->label('Due Date')->native(false)->displayFormat('d/m/Y')->required(),
                TextInput::make('cheque_no')->label('Cheque No.')->maxLength(50),
            );
        }

        array_push($columns,
            TableColumn::make(__('Description')),
            TableColumn::make(__('Salesman')),
            TableColumn::make(__('L/C Expenses')),
            TableColumn::make(__('Cost Center')),
        );
        array_push($schema,
            TextInput::make('description')->label('Description')->maxLength(255),
            AccountingFields::salesman(),
            AccountingFields::accreditation(),
            AccountingFields::costCenter(),
        );

        return Repeater::make('lines')
            ->label('Voucher Lines')
            ->relationship('lines')
            ->table($columns)
            ->schema($schema)
            ->defaultItems(1)
            ->minItems(1)
            ->addActionLabel(__('Add Line'))
            ->reorderable(false)
            ->cloneable()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => static::updateTotal($get, $set))
            ->deleteAction(fn (Action $action) => $action->after(fn (Get $get, Set $set) => static::updateTotal($get, $set)))
            ->columnSpanFull();
    }

    /** @param  array<array-key, array<string, mixed>>  $lines */
    public static function linesTotal(array $lines): float
    {
        return round((float) collect($lines)->sum(fn (array $line): float => (float) ($line['amount'] ?? 0)), 2);
    }

    /** Refresh the lines total; fill the header amount while it is still empty. */
    public static function updateTotal(Get $get, Set $set, string $prefix = ''): void
    {
        $total = static::linesTotal($get($prefix.'lines') ?? []);
        $set($prefix.'lines_total', $total);

        if ((float) $get($prefix.'amount') <= 0) {
            $set($prefix.'amount', $total);
        }
    }
}
