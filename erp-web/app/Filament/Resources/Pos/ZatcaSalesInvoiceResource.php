<?php

namespace App\Filament\Resources\Pos;

use App\Filament\Resources\Pos\Shared\ZatcaResource;
use App\Filament\Resources\Pos\ZatcaSalesInvoiceResource\Pages\ListZatcaSalesInvoices;

/**
 * ZATCA sales invoices (فواتير المبيعات - هيئة الزكاة) — /sales/zatca-sales-invoices.
 */
class ZatcaSalesInvoiceResource extends ZatcaResource
{
    protected static ?string $permissionKey = 'sales.zatca-sales-invoices';

    protected static array $zatcaTypes = ['sales_invoice', 'pos_invoice'];

    protected static ?string $modelLabel = 'ZATCA Sales Invoice';

    protected static ?string $pluralModelLabel = 'ZATCA Sales Invoices';

    protected static ?string $slug = 'sales/zatca-sales-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 90;

    public static function getPages(): array
    {
        return [
            'index' => ListZatcaSalesInvoices::route('/'),
        ];
    }
}
