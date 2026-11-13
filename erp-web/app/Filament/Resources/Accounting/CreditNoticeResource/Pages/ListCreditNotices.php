<?php

namespace App\Filament\Resources\Accounting\CreditNoticeResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\CreditNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotices extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = CreditNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
