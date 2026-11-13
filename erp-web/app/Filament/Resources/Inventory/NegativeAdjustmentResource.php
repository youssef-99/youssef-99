<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\NegativeAdjustmentResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Negative adjustments (التسويات بالعجز) — /inventory/negative-adjustments.
 */
class NegativeAdjustmentResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.negative-adjustments';

    protected static ?string $documentType = 'negative_adjustment';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Negative Adjustment';

    protected static ?string $pluralModelLabel = 'Negative Adjustments';

    protected static ?string $slug = 'inventory/negative-adjustments';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?int $navigationSort = 110;

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::configure($schema, StockDocumentType::NegativeAdjustment);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::NegativeAdjustment);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNegativeAdjustments::route('/'),
            'create' => Pages\CreateNegativeAdjustment::route('/create'),
            'view' => Pages\ViewNegativeAdjustment::route('/{record}'),
            'edit' => Pages\EditNegativeAdjustment::route('/{record}/edit'),
        ];
    }
}
