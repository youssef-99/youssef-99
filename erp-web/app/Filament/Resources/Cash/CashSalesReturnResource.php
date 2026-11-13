<?php

namespace App\Filament\Resources\Cash;

use App\Enums\Module;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\Cash\CashSalesReturnResource\Pages;
use App\Filament\Resources\Cash\Shared\CashSalesDocumentForm;
use App\Filament\Resources\Cash\Shared\CashSalesDocumentTable;
use App\Filament\Resources\ErpResource;
use App\Models\SalesDocument;
use App\Services\CashTransactionService;
use App\Services\SalesDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash sales returns (مرتجعات المبيعات النقدية) — /cash/sales-return-invoices. Original Sales Invoice is required.
 */
class CashSalesReturnResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Cash;

    protected static ?string $permissionKey = 'cash.sales-return-invoices';

    protected static ?string $documentType = 'cash_sales_return';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Cash Sales Return';

    protected static ?string $pluralModelLabel = 'Cash Sales Returns';

    protected static ?string $slug = 'cash/sales-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return CashSalesDocumentForm::configure($schema, SalesDocumentType::CashSalesReturn);
    }

    /** Header fields of the modal "add" (lines are added on the edit page). */
    public static function headerForm(Schema $schema): Schema
    {
        return CashSalesDocumentForm::configure($schema, SalesDocumentType::CashSalesReturn, headerOnly: true);
    }

    /** Start the return with the original invoice lines. */
    public static function afterModalCreate(Model $record): void
    {
        CashTransactionService::copyOriginalLines($record);
    }

    public static function table(Table $table): Table
    {
        return CashSalesDocumentTable::configure($table, static::class, isReturn: true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashSalesReturns::route('/'),
            'view' => Pages\ViewCashSalesReturn::route('/{record}'),
            'edit' => Pages\EditCashSalesReturn::route('/{record}/edit'),
        ];
    }
}
