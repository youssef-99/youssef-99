<?php

namespace App\Filament\Resources\Pos\DoctorResource\Pages;

use App\Filament\Resources\Pos\DoctorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDoctors extends ManageRecords
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
