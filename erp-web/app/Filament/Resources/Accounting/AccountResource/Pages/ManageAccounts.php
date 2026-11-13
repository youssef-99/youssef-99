<?php

namespace App\Filament\Resources\Accounting\AccountResource\Pages;

use App\Filament\Resources\Accounting\AccountResource;
use App\Services\AccountService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageAccounts extends ManageRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add Account')),
            Action::make('printChart')
                ->label('Print Chart of Accounts')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn (): string => route('print', ['type' => 'chart-of-accounts', 'id' => 0]))
                ->openUrlInNewTab(),
            Action::make('exportExcel')
                ->label('Export to Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    fn () => AccountService::writeChartXlsx('php://output'),
                    'chart-of-accounts-'.now()->format('Ymd').'.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                )),
        ];
    }
}
