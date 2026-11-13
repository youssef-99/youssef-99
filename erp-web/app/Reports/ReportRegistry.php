<?php

namespace App\Reports;

use App\Reports\Support\ReportLang;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Catalog of the 13 report groups and every report (URL /admin/reports/<group>/<report>).
 *
 * Report classes are discovered from `app/Reports/<Dir>/*.php` (every concrete subclass of {@see Report});
 * a catalog entry without a class is shown disabled in the hub ("coming soon"). To implement one,
 * create the class with matching `$group` / `$slug` — see the PHPDoc on {@see Report}.
 */
class ReportRegistry
{
    /** Group slug => English label (translated with "rpt." keys). */
    public const GROUPS = [
        'accounts' => 'Accounts',
        'cost-centers' => 'Cost Centers',
        'vat-reports' => 'VAT Reports',
        'final-accounts' => 'Final Accounts',
        'cash-flows' => 'Cash Flows',
        'inventory' => 'Inventory',
        'sales' => 'Sales',
        'customers' => 'Customers',
        'salesmen' => 'Salesmen',
        'purchase' => 'Purchases',
        'suppliers' => 'Suppliers',
        'accreditations' => 'Letters of Credit',
        'financing-contracts' => 'Financing Contracts',
    ];

    /** Group icons for the hub cards and navigation. */
    public const ICONS = [
        'accounts' => 'heroicon-o-calculator',
        'cost-centers' => 'heroicon-o-rectangle-group',
        'vat-reports' => 'heroicon-o-receipt-percent',
        'final-accounts' => 'heroicon-o-scale',
        'cash-flows' => 'heroicon-o-banknotes',
        'inventory' => 'heroicon-o-cube',
        'sales' => 'heroicon-o-shopping-cart',
        'customers' => 'heroicon-o-user-group',
        'salesmen' => 'heroicon-o-user',
        'purchase' => 'heroicon-o-truck',
        'suppliers' => 'heroicon-o-building-office',
        'accreditations' => 'heroicon-o-document-check',
        'financing-contracts' => 'heroicon-o-document-currency-dollar',
    ];

    /** group => [report slug => English title] */
    public const CATALOG = [
        'accounts' => [
            'account-statement' => 'Accounts Statement',
            'accounts-trial-balance' => 'Subsidiary Ledger Trial Balance',
            'trial-balance-analysis' => 'Trial Balance Analysis',
            'accounts-journal-transaction' => 'Journal Transactions',
        ],
        'cost-centers' => [
            'cost-center-statement' => 'Cost Center Statement',
            'cost-center-trial-balance' => 'Cost Centers Trial Balance',
            'account-cost-center-statement' => 'Account Statement by Cost Center',
        ],
        'vat-reports' => [
            'all-vat' => 'VAT Transactions',
            'vat' => 'VAT Return',
        ],
        'final-accounts' => [
            'budget' => 'Balance Sheet',
            'income-list' => 'Income Statement',
            'monthly-income-list' => 'Monthly Income Statement',
        ],
        'cash-flows' => [
            'cash-flows-list' => 'Cash Flow Statement',
        ],
        'inventory' => [
            'items-balances' => 'Items Balances',
            'items-transaction-statement' => 'Items Transaction Statement',
            'items-list-by-expiry-date' => 'Items by Expiry Date',
            'print-item-barcode' => 'Print Item Barcode',
            'item-transaction' => 'Item Transactions',
            'items-prices' => 'Items Prices',
        ],
        'sales' => [
            'daily-sales-details' => 'Daily Sales Details',
            'items-statistics' => 'Items Statistics',
            'sales-statistics' => 'Sales Statistics',
            'top-sales' => 'Top Sales',
            'customer-sales-transaction' => 'Customer Sales Transactions',
            'customer-sales-statistics' => 'Customer Sales Statistics',
            'salesman-customers-sales-statistics' => 'Salesman Customers Sales Statistics',
            'daily-pos-transactions' => 'Daily POS Transactions',
            'points-customers' => 'Points Customers',
            'motivations' => 'Staff Incentives',
            'promotions' => 'Promotions',
            'items-sales-analysis' => 'Items Sales Analysis',
            'user-sales-analysis' => 'User Sales Analysis',
            'user-sales-transaction' => 'User Sales Transactions',
            'doctors-sales-statistics' => 'Doctors Sales Statistics',
            'daily-quotations-details' => 'Daily Quotations Details',
        ],
        'customers' => [
            'customer-statement' => 'Customer Statement',
            'customer-trial-balance' => 'Customers Trial Balance',
            'customers-age-balance' => 'Customers Debt Ageing',
            'customers-group-statement' => 'Customer Group Statement',
        ],
        'salesmen' => [
            'salesman-statement' => 'Salesman Statement',
            'salesmen-sales-analysis' => 'Salesmen Sales Analysis',
            'salesmen-sales-collection' => 'Salesmen Sales and Collections',
        ],
        'purchase' => [
            'item-purchase-details' => 'Item Purchase Details',
            'daily-purchase-details' => 'Daily Purchase Details',
            'daily-foreign-purchase-details' => 'Daily Foreign Purchase Details',
            'daily-purchase-orders-details' => 'Daily Purchase Orders Details',
        ],
        'suppliers' => [
            'supplier-statement' => 'Supplier Statement',
            'supplier-trial-balance' => 'Suppliers Trial Balance',
            'supplier-age-balance' => 'Suppliers Debt Ageing',
        ],
        'accreditations' => [
            'accreditation-statement' => 'Letter of Credit Statement',
            'accreditations-trial-balance' => 'Letters of Credit Trial Balance',
        ],
        'financing-contracts' => [
            'financing-contract-reports' => 'Financing Contracts Report',
        ],
    ];

    /** @var array<string, class-string<Report>>|null */
    protected static ?array $discovered = null;

    /**
     * Implemented reports keyed by "<group>/<slug>".
     *
     * @return array<string, class-string<Report>>
     */
    public static function reports(): array
    {
        if (static::$discovered !== null) {
            return static::$discovered;
        }

        $found = [];
        foreach (glob(app_path('Reports/*/*.php')) ?: [] as $file) {
            $relative = Str::after($file, app_path('Reports').DIRECTORY_SEPARATOR);
            $class = 'App\\Reports\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);
            if (! class_exists($class) || ! is_subclass_of($class, Report::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $found[$class::key()] = $class;
        }

        return static::$discovered = $found;
    }

    /** @return class-string<Report>|null */
    public static function find(string $key): ?string
    {
        return static::reports()[$key] ?? null;
    }

    public static function make(string $key): ?Report
    {
        $class = static::find($key);

        return $class ? app($class) : null;
    }

    public static function exists(string $key): bool
    {
        [$group, $slug] = array_pad(explode('/', $key, 2), 2, '');

        return isset(static::CATALOG[$group][$slug]);
    }

    public static function groupLabel(string $group): string
    {
        return ReportLang::get(static::GROUPS[$group] ?? Str::headline($group));
    }

    public static function title(string $key): string
    {
        if ($class = static::find($key)) {
            return $class::title();
        }
        [$group, $slug] = array_pad(explode('/', $key, 2), 2, '');

        return ReportLang::get(static::CATALOG[$group][$slug] ?? Str::headline($slug));
    }

    public static function permissionKey(string $key): string
    {
        return 'reports.'.str_replace('/', '.', $key);
    }

    public static function canView(string $key): bool
    {
        return (bool) auth()->user()?->hasPermission(static::permissionKey($key).'.view');
    }

    /** True when the user can open at least one implemented report. */
    public static function canViewAny(): bool
    {
        foreach (array_keys(static::reports()) as $key) {
            if (static::exists($key) && static::canView($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hub structure, filtered to what the user may see.
     *
     * @return array<string, array{label: string, icon: string, reports: array<int, array{key: string, slug: string, title: string, implemented: bool}>}>
     */
    public static function hub(?string $onlyGroup = null): array
    {
        $out = [];
        foreach (static::CATALOG as $group => $reports) {
            if ($onlyGroup && $group !== $onlyGroup) {
                continue;
            }
            $items = [];
            $hasImplemented = false;
            foreach (array_keys($reports) as $slug) {
                $key = "{$group}/{$slug}";
                $implemented = static::find($key) !== null;
                $hasImplemented = $hasImplemented || $implemented;
                if ($implemented && ! static::canView($key)) {
                    continue;
                }
                $items[] = ['key' => $key, 'slug' => $slug, 'title' => static::title($key), 'implemented' => $implemented];
            }
            // Hide a group whose reports exist but are all forbidden to this user.
            if ($hasImplemented && array_filter($items, fn ($i) => $i['implemented']) === []) {
                continue;
            }
            $out[$group] = ['label' => static::groupLabel($group), 'icon' => static::ICONS[$group] ?? 'heroicon-o-document-chart-bar', 'reports' => $items];
        }

        return $out;
    }

    /**
     * Permission pages for the group permissions tree: "reports.<group>.<report>" => label.
     * Called by App\Support\PermissionRegistry (which adds the ".view" action).
     *
     * @return array<string, string>
     */
    public static function permissionPages(): array
    {
        $pages = [];
        foreach (static::CATALOG as $group => $reports) {
            foreach (array_keys($reports) as $slug) {
                $key = "{$group}/{$slug}";
                $pages[static::permissionKey($key)] = static::groupLabel($group).' - '.static::title($key);
            }
        }

        return $pages;
    }
}
