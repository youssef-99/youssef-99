<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Enums\PurchaseDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Cash\CashPurchaseReturnResource\Pages;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentForm;
use App\Filament\Resources\Purchases\Shared\PurchaseDocumentTable;
use App\Models\PurchaseDocument;
use App\Services\CashTransactionService;
use App\Services\PurchaseDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash purchase returns (مرتجعات المشتريات النقدية) — /cash/purchase-return-invoices.
 */
class CashPurchaseReturnResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = PurchaseDocument::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.purchase-return-invoices';

    protected static ?string $documentType = 'cash_purchase_return';

    protected static string $documentService = PurchaseDocumentService::class;

    protected static string $printType = 'purchase';

    protected static ?string $modelLabel = 'Cash Purchase Return';

    protected static ?string $pluralModelLabel = 'Cash Purchase Returns';

    protected static ?string $slug = 'cash/purchase-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::CashPurchaseReturn);
    }

    /** Header fields of the modal "add" (lines are added on the edit page). */
    public static function headerForm(Schema $schema): Schema
    {
        return PurchaseDocumentForm::configure($schema, PurchaseDocumentType::CashPurchaseReturn, headerOnly: true);
    }

    /** Start the return with the original invoice lines. */
    public static function afterModalCreate(Model $record): void
    {
        CashTransactionService::copyOriginalLines($record);
    }

    public static function table(Table $table): Table
    {
        return PurchaseDocumentTable::configure($table, static::class, PurchaseDocumentType::CashPurchaseReturn, []);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashPurchaseReturns::route('/'),
            'view' => Pages\ViewCashPurchaseReturn::route('/{record}/view'),
            'edit' => Pages\EditCashPurchaseReturn::route('/{record}/edit'),
        ];
    }
}
