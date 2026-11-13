<?php

namespace App\Filament\Resources\Inventory\OutboundTransferResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Inventory\OutboundTransferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutboundTransfers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = OutboundTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
