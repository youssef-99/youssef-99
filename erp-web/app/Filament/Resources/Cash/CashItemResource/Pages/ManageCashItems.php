<?php

namespace App\Filament\Resources\Cash\CashItemResource\Pages;

use App\Filament\Resources\Cash\CashItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCashItems extends ManageRecords
{
    protected static string $resource = CashItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
