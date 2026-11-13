<?php

namespace App\Filament\Resources\Inventory\ItemGroupResource\Pages;

use App\Filament\Resources\Inventory\ItemGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItemGroups extends ManageRecords
{
    protected static string $resource = ItemGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
