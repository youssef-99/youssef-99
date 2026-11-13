<?php

namespace App\Filament\Resources\Purchases\SupplierGroupResource\Pages;

use App\Filament\Resources\Purchases\SupplierGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSupplierGroups extends ManageRecords
{
    protected static string $resource = SupplierGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
