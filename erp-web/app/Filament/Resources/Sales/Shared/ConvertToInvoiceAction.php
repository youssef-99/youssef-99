<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Enums\Permission;
use App\Enums\SalesDocumentType;
use App\Exceptions\ErpException;
use App\Filament\Resources\Sales\SalesInvoiceResource;
use App\Models\SalesDocument;
use App\Services\SalesDocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * "Convert to Sales Invoice" for quotations and approved customer orders: creates a draft
 * sales invoice through SalesDocumentService::convertToInvoice() and opens it for editing.
 */
class ConvertToInvoiceAction
{
    public static function make(string $label = 'Convert to Sales Invoice'): Action
    {
        return Action::make('convertToInvoice')
            ->label(__($label))
            ->icon('heroicon-o-document-duplicate')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (SalesDocument $record) => static::canConvert($record) && SalesInvoiceResource::userCan(Permission::Create))
            ->action(function (SalesDocument $record, Action $action) {
                try {
                    $invoice = SalesDocumentService::convertToInvoice($record);
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot convert'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('Sales invoice #:number created', ['number' => $invoice->number]))->send();
                $action->redirect(SalesInvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }

    public static function canConvert(SalesDocument $record): bool
    {
        $allowedStatuses = $record->type === SalesDocumentType::CustomerOrder->value
            ? ['approved', 'partially_approved']
            : ['draft', 'confirmed'];

        return in_array($record->status, $allowedStatuses, true) && $record->convertedDocuments()->doesntExist();
    }
}
