<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Cash\CashSalesInvoiceResource\Pages;
use App\Filament\Resources\Cash\Shared\CashSalesDocumentForm;
use App\Filament\Resources\Cash\Shared\CashSalesDocumentTable;
use App\Filament\Resources\ErpResource;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Cash sales invoices (فواتير المبيعات النقدية) — /cash/sales-invoices. Debited to the chosen cash account.
 */
class CashSalesInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.sales-invoices';

    protected static ?string $documentType = 'cash_sales_invoice';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Cash Sales Invoice';

    protected static ?string $pluralModelLabel = 'Cash Sales Invoices';

    protected static ?string $slug = 'cash/sales-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return CashSalesDocumentForm::configure($schema, SalesDocumentType::CashSalesInvoice);
    }

    /** Header fields of the modal "add" (lines are added on the edit page). */
    public static function headerForm(Schema $schema): Schema
    {
        return CashSalesDocumentForm::configure($schema, SalesDocumentType::CashSalesInvoice, headerOnly: true);
    }

    public static function table(Table $table): Table
    {
        return CashSalesDocumentTable::configure($table, static::class, isReturn: false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashSalesInvoices::route('/'),
            'view' => Pages\ViewCashSalesInvoice::route('/{record}'),
            'edit' => Pages\EditCashSalesInvoice::route('/{record}/edit'),
        ];
    }
}
