<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\ForeignPurchaseInvoiceResource\Pages;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Foreign purchase invoices (فواتير المشتريات الخارجية) — /purchase/foreign-purchase-invoices. Currency + exchange rate; shipping cost and purchase expenses are allocated into the items cost; an optional letter of credit is credited instead of the supplier.
 */
class ForeignPurchaseInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.foreign-purchase-invoices';

    protected static ?string $documentType = 'foreign_purchase_invoice';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Foreign Purchase Invoice';

    protected static ?string $pluralModelLabel = 'Foreign Purchase Invoices';

    protected static ?string $slug = 'purchase/foreign-purchase-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::ForeignPurchaseInvoice);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::ForeignPurchaseInvoice, [PurchaseActions::printGoodsReceiptNote(), PurchaseActions::sendToRasd(static::class), PurchaseActions::prepareRasdFile()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForeignPurchaseInvoices::route('/'),
            'create' => Pages\CreateForeignPurchaseInvoice::route('/add'),
            'view' => Pages\ViewForeignPurchaseInvoice::route('/{record}/view'),
            'edit' => Pages\EditForeignPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
