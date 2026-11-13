<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\InboundTransferResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Inbound transfers (التحويلات الواردة): drafts created by posting an outbound transfer; receiving posts them. — /inventory/inbound-transfers.
 */
class InboundTransferResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.inbound-transfers';

    protected static ?string $documentType = 'inbound_transfer';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Inbound Transfer';

    protected static ?string $pluralModelLabel = 'Inbound Transfers';

    protected static ?string $slug = 'inventory/inbound-transfers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected static ?int $navigationSort = 130;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::inbound($schema);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::InboundTransfer);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInboundTransfers::route('/'),
            'view' => Pages\ViewInboundTransfer::route('/{record}/view'),
            'edit' => Pages\EditInboundTransfer::route('/{record}/edit'),
        ];
    }
}
