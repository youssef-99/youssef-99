<?php

namespace App\Filament\Resources\Inventory\AnnualStocktakeResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Inventory\AnnualStocktakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnualStocktakes extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = AnnualStocktakeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
