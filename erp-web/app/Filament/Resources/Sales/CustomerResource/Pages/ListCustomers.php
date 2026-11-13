<?php

namespace App\Filament\Resources\Sales\CustomerResource\Pages;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\CustomerResource;
use App\Models\Customer;
use App\Models\Salesman;
use App\Services\CustomerService;
use App\Services\CustomerTransferService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add Customer')),
            $this->exportAction(),
            $this->transferAction(),
        ];
    }

    /** Export (تصدير): the filtered list as XLSX. */
    protected function exportAction(): Action
    {
        return Action::make('export')
            ->label(__('Export'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (): StreamedResponse {
                $query = $this->getFilteredTableQuery() ?? CustomerResource::getEloquentQuery();

                return response()->streamDownload(
                    fn () => CustomerService::exportXlsx($query),
                    'customers-'.now()->format('Ymd-His').'.xlsx',
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                );
            });
    }

    /** Transfer Customers Between Salesmen (تحويل العملاء بين المندوبين). */
    protected function transferAction(): Action
    {
        $salesmen = fn () => Salesman::query()->orderBy('code')->get()->mapWithKeys(fn (Salesman $s) => [$s->id => $s->label])->all();

        return Action::make('transferCustomers')
            ->label(__('Transfer Customers Between Salesmen'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->visible(fn () => CustomerResource::userCan(Permission::Edit))
            ->modalWidth('2xl')
            ->schema([
                Select::make('from_salesman_id')
                    ->label('From Salesman')
                    ->options($salesmen)
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('customer_ids', [])),
                Select::make('to_salesman_id')
                    ->label('To Salesman')
                    ->options($salesmen)
                    ->searchable()
                    ->required()
                    ->different('from_salesman_id'),
                Select::make('customer_ids')
                    ->label('Customers')
                    ->multiple()
                    ->searchable()
                    ->options(fn (Get $get) => $get('from_salesman_id')
                        ? Customer::query()->where('salesman_id', $get('from_salesman_id'))->orderBy('code')->get()->mapWithKeys(fn (Customer $c) => [$c->id => $c->label])->all()
                        : [])
                    ->helperText(__('Leave empty to transfer all customers of the salesman.')),
            ])
            ->action(function (array $data, Action $action) {
                try {
                    $transfer = CustomerTransferService::transfer((int) $data['from_salesman_id'], (int) $data['to_salesman_id'], $data['customer_ids'] ?? []);
                    Notification::make()->success()->title(__(':count customers transferred', ['count' => count($transfer->customer_ids)]))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot transfer the customers'))->body($e->getMessage())->send();
                    $action->halt();
                }
            });
    }
}
