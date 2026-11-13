<?php

namespace App\Filament\Resources\General\CompanyResource\Pages;

use App\Filament\Resources\General\CompanyResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
