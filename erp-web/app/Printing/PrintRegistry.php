<?php

namespace App\Printing;

use App\Printing\Templates\ChartOfAccountsPrint;
use App\Printing\Templates\ChequeCollectionPrint;
use App\Printing\Templates\GoodsReceiptNotePrint;
use App\Printing\Templates\JournalEntryPrint;
use App\Printing\Templates\PurchaseDocumentPrint;
use App\Printing\Templates\SalesDocumentPrint;
use App\Printing\Templates\StockDocumentPrint;
use App\Printing\Templates\VoucherPrint;

/**
 * Print types served by `route('print', ['type' => ..., 'id' => ...])`.
 */
class PrintRegistry
{
    /** @var array<string, class-string<PrintTemplate>> */
    public const TEMPLATES = [
        'sales' => SalesDocumentPrint::class,
        'purchase' => PurchaseDocumentPrint::class,
        'purchase-grn' => GoodsReceiptNotePrint::class,
        'stock' => StockDocumentPrint::class,
        'voucher' => VoucherPrint::class,
        'journal' => JournalEntryPrint::class,
        'cheque-collection' => ChequeCollectionPrint::class,
        'chart-of-accounts' => ChartOfAccountsPrint::class,
    ];

    public static function resolve(string $type): ?PrintTemplate
    {
        $class = self::TEMPLATES[$type] ?? null;

        return $class ? app($class) : null;
    }
}
