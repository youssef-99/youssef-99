<?php

namespace App\Filament\Resources\Sales\SalesPolicyResource\Pages;

use App\Filament\Resources\Sales\SalesPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSalesPolicies extends ManageRecords
{
    protected static string $resource = SalesPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
