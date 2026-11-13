<?php

namespace App\Filament\Resources\Accounting\DebitNoticeResource\Pages;

use App\Filament\Concerns\HasPostedTabs;
use App\Filament\Resources\Accounting\DebitNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDebitNotices extends ListRecords
{
    use HasPostedTabs;

    protected static string $resource = DebitNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
