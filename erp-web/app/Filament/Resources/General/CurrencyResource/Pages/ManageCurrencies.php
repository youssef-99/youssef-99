<?php

namespace App\Filament\Resources\General\CurrencyResource\Pages;

use App\Filament\Resources\General\CurrencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCurrencies extends ManageRecords
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
