<?php

namespace App\Filament\Resources\Sales\SalesmanResource\Pages;

use App\Filament\Resources\Sales\SalesmanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalesmen extends ManageRecords
{
    protected static string $resource = SalesmanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
