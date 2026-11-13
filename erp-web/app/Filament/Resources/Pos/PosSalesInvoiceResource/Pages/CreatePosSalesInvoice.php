<?php

namespace App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages;

use App\Filament\Resources\Pages\CreateDocumentRecord;
use App\Filament\Resources\Pos\PosSalesInvoiceResource;
use App\Filament\Resources\Pos\Shared\ChecksPosPayment;

/**
 * The POS screen: scan items, take cash / Visa / Mada, save and post, optionally print the 80mm receipt.
 */
class CreatePosSalesInvoice extends CreateDocumentRecord
{
    use ChecksPosPayment;

    protected static string $resource = PosSalesInvoiceResource::class;

    protected function beforeCreate(): void
    {
        $this->assertPaymentCovers();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        $data['time'] = now()->format('H:i:s');
        unset($data['print_invoice']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->receiptRedirect() ?? parent::getRedirectUrl();
    }
}
