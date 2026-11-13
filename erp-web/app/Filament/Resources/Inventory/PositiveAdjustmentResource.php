<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\PositiveAdjustmentResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Positive adjustments (التسويات بالزيادة) — /inventory/positive-adjustments.
 */
class PositiveAdjustmentResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.positive-adjustments';

    protected static ?string $documentType = 'positive_adjustment';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Positive Adjustment';

    protected static ?string $pluralModelLabel = 'Positive Adjustments';

    protected static ?string $slug = 'inventory/positive-adjustments';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 100;

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::configure($schema, StockDocumentType::PositiveAdjustment);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::PositiveAdjustment);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPositiveAdjustments::route('/'),
            'create' => Pages\CreatePositiveAdjustment::route('/create'),
            'view' => Pages\ViewPositiveAdjustment::route('/{record}'),
            'edit' => Pages\EditPositiveAdjustment::route('/{record}/edit'),
        ];
    }
}
