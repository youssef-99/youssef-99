<?php

namespace App\Filament\Resources\Sales\Shared;

use App\Enums\SalesDocumentType;

/**
 * Labels and colours of the sales_documents.status workflow column.
 */
class SalesDocumentStatus
{
    /** @return array<string, string> */
    public static function options(?SalesDocumentType $type = null): array
    {
        return [
            'draft' => __('Draft'),
            'confirmed' => $type === SalesDocumentType::CustomerOrder ? __('Posted') : __('Confirmed'),
            'partially_approved' => __('Partially Approved'),
            'approved' => __('Approved'),
            'rejected' => __('Rejected'),
        ];
    }

    public static function label(?string $status, ?SalesDocumentType $type = null): string
    {
        return static::options($type)[$status] ?? (string) $status;
    }

    public static function color(?string $status): string
    {
        return match ($status) {
            'confirmed' => 'info',
            'approved' => 'success',
            'partially_approved' => 'warning',
            'rejected' => 'danger',
            default => 'gray',
        };
    }
}
