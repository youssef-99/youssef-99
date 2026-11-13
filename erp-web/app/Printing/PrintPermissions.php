<?php

namespace App\Printing;

use App\Models\Account;
use App\Models\ChequeCollection;
use App\Models\FinancingContract;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\SalesDocument;
use App\Models\StockDocument;
use App\Models\User;
use App\Models\Voucher;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Resolves which page permission keys allow printing a record.
 *
 * A user may print a record when they can view ANY page that lists it: every registered
 * resource whose model (and document type, for document resources) matches the record,
 * plus a fallback map of the expected page keys (pages built by other modules).
 */
class PrintPermissions
{
    /**
     * Expected page keys per model and `type` column ('*' = any type).
     *
     * @var array<class-string<Model>, array<string, array<int, string>>>
     */
    public const FALLBACK_KEYS = [
        SalesDocument::class => [
            'quotation' => ['sales.quotations'],
            'customer_order' => ['sales.customer-orders'],
            'sales_invoice' => ['sales.sales-invoices', 'sales.zatca-sales-invoices'],
            'sales_return' => ['sales.sales-return-invoices', 'sales.zatca-sales-return-invoices', 'sales.zatca-credit-notes'],
            'pos_invoice' => ['sales.pos-sales-invoices'],
            'pos_return' => ['sales.pos-sales-return-invoices'],
            'cash_sales_invoice' => ['cash.sales-invoices', 'cash.zatca-sales-invoices'],
            'cash_sales_return' => ['cash.sales-return-invoices', 'cash.zatca-sales-return-invoices'],
        ],
        PurchaseDocument::class => [
            'purchase_order' => ['purchases.purchase-orders'],
            'purchase_invoice' => ['purchases.purchase-invoices'],
            'purchase_return' => ['purchases.purchase-return-invoices'],
            'foreign_purchase_invoice' => ['purchases.foreign-purchase-invoices'],
            'cash_purchase_invoice' => ['cash.purchase-invoices'],
            'cash_purchase_return' => ['cash.purchase-return-invoices'],
        ],
        StockDocument::class => [
            'positive_adjustment' => ['inventory.positive-adjustments'],
            'negative_adjustment' => ['inventory.negative-adjustments'],
            'outbound_transfer' => ['inventory.outbound-transfers'],
            'inbound_transfer' => ['inventory.inbound-transfers'],
            'annual_stocktake' => ['inventory.annual-inventory-minutes'],
            'periodic_stocktake' => ['inventory.periodic-inventory-minutes', 'inventory.periodic-inventory-approval'],
        ],
        Voucher::class => [
            'receipt_cash' => ['accounting.receipt-vouchers'],
            'receipt_cheque' => ['accounting.receipt-cheque-vouchers'],
            'payment_cash' => ['accounting.payment-cash-vouchers'],
            'payment_cheque' => ['accounting.payment-cheque-vouchers'],
            'credit_notice' => ['accounting.notice-creditors'],
            'debit_notice' => ['accounting.notice-debtors'],
        ],
        JournalEntry::class => ['*' => ['accounting.journal-vouchers']],
        ChequeCollection::class => ['*' => ['accounting.cheques-collection']],
        Account::class => ['*' => ['accounting.accounts']],
        FinancingContract::class => ['*' => ['accounting.financing-contracts']],
    ];

    /**
     * Page permission keys (without the ".view" suffix) that cover the record.
     *
     * @return array<int, string>
     */
    public static function keysFor(Model $record): array
    {
        $type = $record->getAttribute('type');
        $keys = [];

        foreach (static::resources() as $resource) {
            if (! method_exists($resource, 'getPermissionKey') || $resource::getModel() !== $record::class) {
                continue;
            }

            $documentType = method_exists($resource, 'getDocumentType') ? $resource::getDocumentType() : null;

            if ($documentType !== null && $documentType !== $type) {
                continue;
            }

            $keys[] = $resource::getPermissionKey();
        }

        $fallback = static::FALLBACK_KEYS[$record::class] ?? [];

        return array_values(array_unique([...$keys, ...($fallback[(string) $type] ?? []), ...($fallback['*'] ?? [])]));
    }

    /**
     * @param  array<int, string>  $keys
     */
    public static function allows(?User $user, array $keys): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        foreach ($keys as $key) {
            if ($user->hasPermission("{$key}.view")) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, class-string> */
    protected static function resources(): array
    {
        try {
            return Filament::getPanel('admin')->getResources();
        } catch (Throwable) {
            return [];
        }
    }
}
