<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Filament\Resources\Cash\CashZatcaSalesInvoiceResource\Pages\ListCashZatcaSalesInvoices;
use App\Filament\Resources\Pos\Shared\ZatcaDocumentTable;
use App\Filament\Resources\Pos\Shared\ZatcaResource;
use Filament\Tables\Table;

/**
 * ZATCA Sales Invoices (فواتير المبيعات الضريبية النقدية) — /cash/zatca-sales-invoices.
 * Posted cash documents with their ZATCA submission status (shared POS ZATCA table).
 */
class CashZatcaSalesInvoiceResource extends ZatcaResource
{
    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.zatca-sales-invoices';

    /** @var array<int, string> */
    protected static array $zatcaTypes = ['cash_sales_invoice'];

    protected static ?string $modelLabel = 'ZATCA Sales Invoice';

    protected static ?string $pluralModelLabel = 'ZATCA Sales Invoices';

    protected static ?string $slug = 'cash/zatca-sales-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 90;

    public static function table(Table $table): Table
    {
        return ZatcaDocumentTable::configure($table, static::class, static::$zatcaTypes, []);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashZatcaSalesInvoices::route('/'),
        ];
    }
}
