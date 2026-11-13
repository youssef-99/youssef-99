<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\SalesReturnResource\Pages;
use App\Filament\Resources\Sales\Shared\SalesDocumentForm;
use App\Filament\Resources\Sales\Shared\SalesDocumentTable;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Sales returns (مرتجعات المبيعات) — /sales/sales-return-invoices.
 * Posting reverses the invoice entry and brings stock back in; the returned quantity per item
 * cannot exceed the sold quantity minus earlier returns (validated in SalesDocumentService).
 */
class SalesReturnResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.sales-return-invoices';

    protected static ?string $documentType = 'sales_return';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Sales Return';

    protected static ?string $pluralModelLabel = 'Sales Returns';

    protected static ?string $slug = 'sales/sales-return-invoices';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 55;

    public static function form(Schema $schema): Schema
    {
        return SalesDocumentForm::configure($schema, SalesDocumentType::SalesReturn);
    }

    public static function table(Table $table): Table
    {
        return SalesDocumentTable::configure($table, static::class, [
            TextColumn::make('originalDocument.number')->label('Original Invoice No.')->searchable()->placeholder('-'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesReturns::route('/'),
            'create' => Pages\CreateSalesReturn::route('/add'),
            'view' => Pages\ViewSalesReturn::route('/{record}/view'),
            'edit' => Pages\EditSalesReturn::route('/{record}/edit'),
        ];
    }
}
