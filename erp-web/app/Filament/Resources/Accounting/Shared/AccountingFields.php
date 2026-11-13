<?php

namespace App\Filament\Resources\Accounting\Shared;

use App\Enums\AccountType;
use App\Filament\Support\Fields;
use App\Models\Account;
use App\Models\Accreditation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Form fields shared by the accounting line grids (journal and cash box vouchers).
 */
class AccountingFields
{
    /** Line account select that also fills the read-only "Account Type" cell. */
    public static function lineAccount(string $label = 'Account Name'): Select
    {
        return Fields::account(label: $label)
            ->required()
            ->live()
            ->afterStateUpdated(fn ($state, Set $set) => $set('account_type', static::accountTypeLabel($state)));
    }

    /** Read-only "Account Type" (نوع الحساب) of the line account. */
    public static function accountType(): TextInput
    {
        return TextInput::make('account_type')
            ->label('Account Type')
            ->readOnly()
            ->dehydrated(false)
            ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(static::accountTypeLabel($get('account_id'))));
    }

    public static function accountTypeLabel(mixed $accountId): ?string
    {
        if (blank($accountId)) {
            return null;
        }

        return AccountType::labelFor(Account::query()->whereKey($accountId)->value('type'));
    }

    public static function salesman(): Select
    {
        return Fields::relation('salesman_id', 'salesman', 'Salesman')->preload(false);
    }

    public static function costCenter(): Select
    {
        return Fields::relation('cost_center_id', 'costCenter', 'Cost Center', modifyQuery: fn ($query) => $query->where('is_active', true))->preload(false);
    }

    /** Letter of credit whose expenses the line carries (مصاريف الاعتماد). */
    public static function accreditation(): Select
    {
        return Select::make('accreditation_id')
            ->label('L/C Expenses')
            ->relationship('accreditation', 'number')
            ->getOptionLabelFromRecordUsing(fn (Accreditation $record) => (string) $record->number)
            ->searchable(['number']);
    }

    public static function bank(string $name = 'bank_id', string $relationship = 'bank', string $label = 'Bank'): Select
    {
        return Fields::relation($name, $relationship, $label)->preload(false);
    }
}
