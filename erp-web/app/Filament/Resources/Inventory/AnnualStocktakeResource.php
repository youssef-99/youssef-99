<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\AnnualStocktakeResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Annual stocktake records (محاضر الجرد السنوي). Posting books the surplus / shortage against the book balance. — /inventory/annual-inventory-minutes.
 */
class AnnualStocktakeResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.annual-inventory-minutes';

    protected static ?string $documentType = 'annual_stocktake';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Annual Stocktake Record';

    protected static ?string $pluralModelLabel = 'Annual Stocktake Records';

    protected static ?string $slug = 'inventory/annual-inventory-minutes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 140;

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::configure($schema, StockDocumentType::AnnualStocktake);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::AnnualStocktake);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnualStocktakes::route('/'),
            'create' => Pages\CreateAnnualStocktake::route('/create'),
            'view' => Pages\ViewAnnualStocktake::route('/{record}'),
            'edit' => Pages\EditAnnualStocktake::route('/{record}/edit'),
        ];
    }
}
