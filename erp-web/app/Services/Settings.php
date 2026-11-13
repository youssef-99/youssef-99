<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Simple key/value settings stored in the `settings` table (cached).
 *
 * Default account keys used by the posting engine (values are account ids):
 *   accounts.cash, accounts.card, accounts.receivables, accounts.payables,
 *   accounts.sales, accounts.sales_returns, accounts.sales_discount,
 *   accounts.vat_output, accounts.vat_input, accounts.inventory, accounts.cogs,
 *   accounts.purchase_discount, accounts.cheques_under_collection,
 *   accounts.inventory_adjustment, accounts.stock_in_transit, accounts.opening_balances,
 *   accounts.foreign_purchase_expenses
 */
class Settings
{
    public const ACCOUNT_KEYS = [
        'accounts.cash' => 'Cash Account',
        'accounts.card' => 'Card (Mada/Visa) Account',
        'accounts.receivables' => 'Customers (Receivables) Parent Account',
        'accounts.payables' => 'Suppliers (Payables) Parent Account',
        'accounts.sales' => 'Sales Account',
        'accounts.sales_returns' => 'Sales Returns Account',
        'accounts.sales_discount' => 'Sales Discount Account',
        'accounts.vat_output' => 'Output VAT Account',
        'accounts.vat_input' => 'Input VAT Account',
        'accounts.inventory' => 'Inventory Account',
        'accounts.cogs' => 'Cost of Goods Sold Account',
        'accounts.purchase_discount' => 'Purchase Discount Account',
        'accounts.cheques_under_collection' => 'Cheques Under Collection Account',
        'accounts.inventory_adjustment' => 'Inventory Adjustment Account',
        'accounts.stock_in_transit' => 'Stock in Transit Account',
        'accounts.opening_balances' => 'Opening Balances Account',
        'accounts.foreign_purchase_expenses' => 'Foreign Purchase Expenses Account',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('erp.settings', fn () => Setting::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('erp.settings');
    }

    public static function account(string $key): ?int
    {
        $id = static::get("accounts.{$key}");

        return $id ? (int) $id : null;
    }

    public static function accountOrFail(string $key): int
    {
        return static::account($key) ?? throw new \App\Exceptions\ErpException(
            __('Default account ":key" is not configured. Set it in General > System Settings.', ['key' => __(self::ACCOUNT_KEYS["accounts.{$key}"] ?? $key)])
        );
    }
}
