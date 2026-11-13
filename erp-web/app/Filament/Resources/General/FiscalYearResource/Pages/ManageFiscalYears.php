<?php

namespace App\Filament\Resources\General\FiscalYearResource\Pages;

use App\Filament\Resources\General\FiscalYearResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFiscalYears extends ManageRecords
{
    protected static string $resource = FiscalYearResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
