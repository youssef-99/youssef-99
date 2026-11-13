<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\PeriodicStocktakeResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Periodic stocktake records (محاضر الجرد الدوري); approved and posted from the approval page. — /inventory/periodic-inventory-minutes.
 */
class PeriodicStocktakeResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.periodic-inventory-minutes';

    protected static ?string $documentType = 'periodic_stocktake';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Periodic Stocktake Record';

    protected static ?string $pluralModelLabel = 'Periodic Stocktake Records';

    protected static ?string $slug = 'inventory/periodic-inventory-minutes';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 150;

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::configure($schema, StockDocumentType::PeriodicStocktake);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::PeriodicStocktake);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPeriodicStocktakes::route('/'),
            'create' => Pages\CreatePeriodicStocktake::route('/create'),
            'view' => Pages\ViewPeriodicStocktake::route('/{record}'),
            'edit' => Pages\EditPeriodicStocktake::route('/{record}/edit'),
        ];
    }
}
