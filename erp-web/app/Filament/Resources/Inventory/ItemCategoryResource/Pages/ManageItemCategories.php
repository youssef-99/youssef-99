<?php

namespace App\Filament\Resources\Inventory\ItemCategoryResource\Pages;

use App\Filament\Resources\Inventory\ItemCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageItemCategories extends ManageRecords
{
    protected static string $resource = ItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
