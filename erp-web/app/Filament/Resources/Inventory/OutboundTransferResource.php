<?php

namespace App\Filament\Resources\Inventory;

use App\Enums\Module;
use App\Enums\StockDocumentType;
use App\Filament\Concerns\IsDocumentResource;
use App\Filament\Resources\ErpResource;
use App\Filament\Resources\Inventory\OutboundTransferResource\Pages;
use App\Filament\Resources\Inventory\Shared\StockDocumentForm;
use App\Filament\Resources\Inventory\Shared\StockDocumentTable;
use App\Models\StockDocument;
use App\Services\StockDocumentService;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

/**
 * Outbound transfers (التحويلات المنصرفة). Posting creates the draft inbound transfer at the destination. — /inventory/outbound-transfers.
 */
class OutboundTransferResource extends ErpResource
{
    use IsDocumentResource;

    protected static ?string $model = StockDocument::class;

    protected static ?Module $module = Module::Inventory;

    protected static ?string $permissionKey = 'inventory.outbound-transfers';

    protected static ?string $documentType = 'outbound_transfer';

    protected static string $documentService = StockDocumentService::class;

    protected static string $printType = 'stock';

    protected static ?string $modelLabel = 'Outbound Transfer';

    protected static ?string $pluralModelLabel = 'Outbound Transfers';

    protected static ?string $slug = 'inventory/outbound-transfers';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-on-square';

    protected static ?int $navigationSort = 120;

    public static function form(Schema $schema): Schema
    {
        return StockDocumentForm::configure($schema, StockDocumentType::OutboundTransfer);
    }

    public static function table(Table $table): Table
    {
        return StockDocumentTable::configure($table, static::class, StockDocumentType::OutboundTransfer);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutboundTransfers::route('/'),
            'create' => Pages\CreateOutboundTransfer::route('/create'),
            'view' => Pages\ViewOutboundTransfer::route('/{record}'),
            'edit' => Pages\EditOutboundTransfer::route('/{record}/edit'),
        ];
    }
}
