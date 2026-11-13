<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Cash\CashPurchaseInvoiceResource\Pages;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Cash purchase invoices (فواتير المشتريات النقدية) — /cash/purchase-invoices. Credited to the chosen cash account.
 */
class CashPurchaseInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.purchase-invoices';

    protected static ?string $documentType = 'cash_purchase_invoice';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Cash Purchase Invoice';

    protected static ?string $pluralModelLabel = 'Cash Purchase Invoices';

    protected static ?string $slug = 'cash/purchase-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::CashPurchaseInvoice);
    }

    /** Header fields of the modal "add" (lines are added on the edit page). */
    public static function headerForm(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::CashPurchaseInvoice, headerOnly: true);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::CashPurchaseInvoice, []);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashPurchaseInvoices::route('/'),
            'view' => Pages\ViewCashPurchaseInvoice::route('/{record}'),
            'edit' => Pages\EditCashPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
