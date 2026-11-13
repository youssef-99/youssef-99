<?php

namespace App\Filament\Resources\Pos\DrugDoseResource\Pages;

use App\Filament\Resources\Pos\DrugDoseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDrugDoses extends ManageRecords
{
    protected static string $resource = DrugDoseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
