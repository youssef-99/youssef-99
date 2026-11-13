<?php

namespace App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages;

use App\Filament\Resources\Pages\EditDocumentRecord;
use App\Filament\Resources\Pos\PosSalesInvoiceResource;
use App\Filament\Resources\Pos\Shared\ChecksPosPayment;

class EditPosSalesInvoice extends EditDocumentRecord
{
    use ChecksPosPayment;

    protected static string $resource = PosSalesInvoiceResource::class;

    protected function beforeSave(): void
    {
        $this->assertPaymentCovers();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['print_invoice']);

        return parent::mutateFormDataBeforeSave($data);
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->receiptRedirect() ?? parent::getRedirectUrl();
    }
}
