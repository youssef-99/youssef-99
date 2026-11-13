<?php

namespace App\Filament\Resources\Accounting\JournalVoucherResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\JournalVoucherResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalVouchers extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = JournalVoucherResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
