<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\PurchaseReturnResource\Pages;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Purchase returns (مرتجعات المشتريات) — /purchase/purchase-return-invoices. Against an invoice (cannot exceed the received quantity) or without reference.
 */
class PurchaseReturnResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.purchase-return-invoices';

    protected static ?string $documentType = 'purchase_return';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Purchase Return';

    protected static ?string $pluralModelLabel = 'Purchase Returns';

    protected static ?string $slug = 'purchase/purchase-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::PurchaseReturn);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::PurchaseReturn, []);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseReturns::route('/'),
            'create' => Pages\CreatePurchaseReturn::route('/add'),
            'view' => Pages\ViewPurchaseReturn::route('/{record}/view'),
            'edit' => Pages\EditPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
