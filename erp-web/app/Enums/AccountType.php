<?php

namespace App\Enums;

/**
 * Account types (نوع الحساب) stored in `accounts.type`.
 */
enum AccountType: string
{
    case General = 'general';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Bank = 'bank';
    case Cash = 'cash';
    case Inventory = 'inventory';
    case Sales = 'sales';
    case Purchases = 'purchases';
    case Vat = 'vat';
    case Expense = 'expense';
    case Revenue = 'revenue';
    case Equity = 'equity';
    case LetterOfCredit = 'lc';

    public function label(): string
    {
        return __(match ($this) {
            self::General => 'General',
            self::Customer => 'Customer',
            self::Supplier => 'Supplier',
            self::Bank => 'Bank',
            self::Cash => 'Cash',
            self::Inventory => 'Inventory',
            self::Sales => 'Sales',
            self::Purchases => 'Purchases',
            self::Vat => 'VAT',
            self::Expense => 'Expense',
            self::Revenue => 'Revenue',
            self::Equity => 'Equity',
            self::LetterOfCredit => 'Letter of Credit',
        });
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public static function labelFor(?string $value): ?string
    {
        return $value === null ? null : (self::tryFrom($value)?->label() ?? $value);
    }

    /** @return array<string, string> account nature (طبيعة الحساب) */
    public static function natureOptions(): array
    {
        return ['debit' => __('Debit (Dr)'), 'credit' => __('Credit (Cr)')];
    }

    /** @return array<string, string> balance sheet item (بند الميزانية) */
    public static function balanceSheetOptions(): array
    {
        return [
            'assets' => __('Assets'),
            'liabilities' => __('Liabilities'),
            'equity' => __('Equity'),
            'revenue' => __('Revenue'),
            'expenses' => __('Expenses'),
        ];
    }

    /** @return array<string, string> cheque status (حالة الشيك) */
    public static function chequeStatusOptions(): array
    {
        return [
            'under_collection' => __('Under Collection'),
            'collected' => __('Collected'),
            'returned' => __('Returned'),
        ];
    }
}
