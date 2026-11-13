<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\SalesInvoiceResource\Pages;
use App\Filament\Resources\Sales\Shared\SalesDocumentForm;
use App\Filament\Resources\Sales\Shared\SalesDocumentTable;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Sales invoices (فواتير المبيعات) — /sales/sales-invoices.
 * Reference implementation of a document resource.
 */
class SalesInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.sales-invoices';

    protected static ?string $documentType = 'sales_invoice';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Sales Invoice';

    protected static ?string $pluralModelLabel = 'Sales Invoices';

    protected static ?string $slug = 'sales/sales-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return SalesDocumentForm::configure($schema, SalesDocumentType::SalesInvoice);
    }

    public static function table(Table $table): Table
    {
        return SalesDocumentTable::configure($table, static::class);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesInvoices::route('/'),
            'create' => Pages\CreateSalesInvoice::route('/add'),
            'view' => Pages\ViewSalesInvoice::route('/{record}/view'),
            'edit' => Pages\EditSalesInvoice::route('/{record}/edit'),
        ];
    }
}
