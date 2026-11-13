<?php

namespace App\Filament\Resources\Purchases\Shared;

use App\Enums\Permission;
use App\Exceptions\ErpException;
use App\Filament\Actions\DocumentActions;
use App\Filament\Resources\Purchases\PurchaseInvoiceResource;
use App\Models\PurchaseDocument;
use App\Services\PurchaseDocumentService;
use App\Services\RasdService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Purchases-specific row / header actions: goods receipt note, Rasd, PO conversion, back.
 */
class PurchaseActions
{
    /** طباعة إذن إدخال بضاعة */
    public static function printGoodsReceiptNote(): Action
    {
        return DocumentActions::print('purchase-grn', 'printGrn', 'Print Goods Receipt Note')->icon('heroicon-o-clipboard-document-check');
    }

    /** إرسال إلى رصد */
    public static function sendToRasd(string $resource): Action
    {
        return Action::make('sendToRasd')
            ->label(__('Send to Rasd'))
            ->icon('heroicon-o-paper-airplane')
            ->color('info')
            ->requiresConfirmation()
            ->visible(fn (PurchaseDocument $record) => $record->is_posted && $resource::userCan(Permission::Post))
            ->action(function (PurchaseDocument $record, Action $action) {
                try {
                    RasdService::send($record);
                    Notification::make()->success()->title(__('Sent to Rasd'))->body($record->rasd_reference)->send();
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Rasd'))->body($e->getMessage())->persistent()->send();
                    $action->halt();
                }
            });
    }

    /** تجهيز ملف رصد: builds the file, stores it on the invoice and downloads it. */
    public static function prepareRasdFile(): Action
    {
        return Action::make('prepareRasdFile')
            ->label(__('Prepare Rasd File'))
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (PurchaseDocument $record) => $record->is_posted)
            ->action(function (PurchaseDocument $record, Action $action): ?StreamedResponse {
                try {
                    RasdService::prepare($record);
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Rasd'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return null;
                }

                $content = (string) $record->rasd_file;

                return response()->streamDownload(function () use ($content): void {
                    echo $content;
                }, RasdService::fileName($record), ['Content-Type' => 'application/xml']);
            });
    }

    /** تحويل إلى فاتورة مشتريات */
    public static function convertToInvoice(): Action
    {
        return Action::make('convertToInvoice')
            ->label(__('Convert to Purchase Invoice'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (PurchaseDocument $record) => $record->status !== 'closed' && PurchaseInvoiceResource::userCan(Permission::Create))
            ->action(function (PurchaseDocument $record, Action $action) {
                try {
                    $invoice = PurchaseDocumentService::convertOrderToInvoice($record);
                } catch (ErpException $e) {
                    Notification::make()->danger()->title(__('Cannot convert'))->body($e->getMessage())->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('Purchase invoice :number created', ['number' => $invoice->number]))->send();
                $action->redirect(PurchaseInvoiceResource::getUrl('edit', ['record' => $invoice]));
            });
    }

    /** عودة */
    public static function back(string $resource): Action
    {
        return Action::make('back')
            ->label(__('Back'))
            ->icon('heroicon-o-arrow-uturn-right')
            ->color('gray')
            ->url(fn () => $resource::getUrl('index'));
    }
}
