<?php

namespace App\Filament\Resources\Inventory\InboundTransferResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Inventory\InboundTransferResource;
use Filament\Resources\Pages\ListRecords;

class ListInboundTransfers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = InboundTransferResource::class;
}
