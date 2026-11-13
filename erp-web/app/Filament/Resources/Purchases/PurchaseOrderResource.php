<?php

namespace App\Filament\Resources\Purchases;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\PurchaseOrderResource\Pages;
use App\Filament\Resources\Purchases\Shared\PurchaseActions;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Purchase orders (أوامر الشراء) — /purchase/purchase-orders. Header added in a modal; lines are added on the edit page after saving.
 */
class PurchaseOrderResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Purchases;

    protected static ?string $permissionKey = 'purchases.purchase-orders';

    protected static ?string $documentType = 'purchase_order';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Purchase Order';

    protected static ?string $pluralModelLabel = 'Purchase Orders';

    protected static ?string $slug = 'purchase/purchase-orders';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::PurchaseOrder);
    }

    /** Header fields of the modal "add" (lines are added on the edit page). */
    public static function headerForm(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::PurchaseOrder, headerOnly: true);
    }

    /** Orders are never posted; they stay editable until converted to an invoice. */
    public static function canEdit(Model $record): bool
    {
        return $record->status !== 'closed' && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record->status !== 'closed' && parent::canDelete($record);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::PurchaseOrder, [PurchaseActions::convertToInvoice()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
