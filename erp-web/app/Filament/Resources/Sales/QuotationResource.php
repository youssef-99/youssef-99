<?php

namespace App\Filament\Resources\Sales;

use App\Enums\Module;
use App\Enums\SalesDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Sales\QuotationResource\Pages;
use App\Filament\Resources\Sales\Shared\ConvertToInvoiceAction;
use App\Filament\Resources\Sales\Shared\SalesDocumentForm;
use App\Filament\Resources\Sales\Shared\SalesDocumentTable;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Quotations (عروض الأسعار) — /sales/quotations.
 * Confirm marks the quotation as confirmed (no stock / journal); "Convert to Sales Invoice"
 * copies its lines into a new draft invoice.
 */
class QuotationResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = SalesDocument::class;

    protected static ?Module $module = Module::Sales;

    protected static ?string $permissionKey = 'sales.quotations';

    protected static ?string $documentType = 'quotation';

    protected static string $documentService = SalesDocumentService::class;

    protected static string $printType = 'sales';

    protected static ?string $modelLabel = 'Quotation';

    protected static ?string $pluralModelLabel = 'Quotations';

    protected static ?string $slug = 'sales/quotations';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return SalesDocumentForm::configure($schema, SalesDocumentType::Quotation);
    }

    public static function table(Table $table): Table
    {
        return SalesDocumentTable::configure(
            $table,
            static::class,
            extraActions: [ConvertToInvoiceAction::make()],
            numberLabel: 'Quotation No.',
            postLabel: 'Confirm',
            statusType: SalesDocumentType::Quotation,
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/add'),
            'view' => Pages\ViewQuotation::route('/{record}/view'),
            'edit' => Pages\EditQuotation::route('/{record}/edit'),
        ];
    }
}
