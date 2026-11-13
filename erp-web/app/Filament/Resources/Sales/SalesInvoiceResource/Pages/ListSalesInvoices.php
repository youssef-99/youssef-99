<?php

namespace App\Filament\Resources\Sales\SalesInvoiceResource\Pages;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\SalesInvoiceResource;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSalesInvoices extends ListRecords
{
    protected static string $resource = SalesInvoiceResource::class;

    /** All / Confirmed / Unconfirmed (الكل / المؤكدة / الغير مؤكدة) */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All')),
            'confirmed' => Tab::make(__('Confirmed'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', true)),
            'unconfirmed' => Tab::make(__('Unconfirmed'))->modifyQueryUsing(fn (Builder $query) => $query->where('is_posted', false)),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('postAll')
                ->label(__('Post Invoices to Accounting'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => SalesInvoiceResource::userCan(Permission::Post))
                ->action(function () {
                    $ok = 0;
                    $errors = [];
                    SalesInvoiceResource::getEloquentQuery()->where('is_posted', false)->get()
                        ->each(function (SalesDocument $doc) use (&$ok, &$errors) {
                            try {
                                SalesDocumentService::post($doc);
                                $ok++;
                            } catch (ErpException $e) {
                                $errors[] = "#{$doc->number}: {$e->getMessage()}";
                            }
                        });
                    Notification::make()
                        ->title(__(':count invoices posted', ['count' => $ok]))
                        ->body(implode("\n", $errors))
                        ->color($errors ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }
}
