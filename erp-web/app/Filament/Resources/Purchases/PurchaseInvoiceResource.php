<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource\Pages;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Purchase invoices (فواتير المشتريات) — /purchase/purchase-invoices. Row actions include the goods receipt note and Rasd (SFDA track & trace).
 */
class PurchaseInvoiceResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.purchase-invoices';

    protected static ?string $documentType = 'purchase_invoice';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Purchase Invoice';

    protected static ?string $pluralModelLabel = 'Purchase Invoices';

    protected static ?string $slug = 'purchase/purchase-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::PurchaseInvoice);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::PurchaseInvoice, [PurchaseActions::printGoodsReceiptNote(), PurchaseActions::sendToRasd(static::class), PurchaseActions::prepareRasdFile()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseInvoices::route('/'),
            'create' => Pages\CreatePurchaseInvoice::route('/create'),
            'view' => Pages\ViewPurchaseInvoice::route('/{record}'),
            'edit' => Pages\EditPurchaseInvoice::route('/{record}/edit'),
        ];
    }
}
