<?php

namespace App\Filament\Resources\Inventory\ActiveIngredientResource\Pages;

use App\Filament\Resources\Inventory\ActiveIngredientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageActiveIngredients extends ManageRecords
{
    protected static string $resource = ActiveIngredientResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
