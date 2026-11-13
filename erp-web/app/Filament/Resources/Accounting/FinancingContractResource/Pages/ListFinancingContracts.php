<?php

namespace App\Filament\Resources\Accounting\FinancingContractResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\FinancingContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFinancingContracts extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = FinancingContractResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
