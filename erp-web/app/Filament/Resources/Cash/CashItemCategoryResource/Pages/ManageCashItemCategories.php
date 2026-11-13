<?php

namespace App\Filament\Resources\Cash\CashItemCategoryResource\Pages;

use App\Filament\Resources\Cash\CashItemCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCashItemCategories extends ManageRecords
{
    protected static string $resource = CashItemCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
