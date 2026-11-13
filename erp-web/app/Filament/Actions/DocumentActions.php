<?php

namespace App\Filament\Actions;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Post / Unpost / Print actions for documents.
 *
 * $service must expose static post(Model) and unpost(Model). $resource is the ErpResource class (for permissions).
 * Works as a table row action or a page header action.
 */
class DocumentActions
{
    public static function post(string $service, string $resource, string $label = 'Post'): Action
    {
        return Action::make('post')
            ->label(__($label))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Model $record) => ! $record->is_posted && $resource::userCan(Permission::Post))
            ->action(function (Model $record, Action $action) use ($service) {
                try {
                    $service::post($record);
                    Notification::make()->success()->title(__('Posted successfully'))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot post'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    public static function unpost(string $service, string $resource): Action
    {
        return Action::make('unpost')
            ->label(__('Unpost'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Model $record) => $record->is_posted && $resource::userCan(Permission::Post))
            ->action(function (Model $record, Action $action) use ($service) {
                try {
                    $service::unpost($record);
                    Notification::make()->success()->title(__('Unposted'))->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot unpost'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    /** Opens the printable document (PrintController) in a new tab. */
    public static function print(string $type, string $name = 'print', string $label = 'Print'): Action
    {
        return Action::make($name)
            ->label(__($label))
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (Model $record) => route('print', ['type' => $type, 'id' => $record->getKey()]))
            ->openUrlInNewTab();
    }
}
