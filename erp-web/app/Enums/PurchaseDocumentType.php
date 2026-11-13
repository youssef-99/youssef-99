<?php

namespace App\Enums;

enum PurchaseDocumentType: string
{
    case PurchaseOrder = 'purchase_order';
    case PurchaseInvoice = 'purchase_invoice';
    case PurchaseReturn = 'purchase_return';
    case ForeignPurchaseInvoice = 'foreign_purchase_invoice';
    case CashPurchaseInvoice = 'cash_purchase_invoice';
    case CashPurchaseReturn = 'cash_purchase_return';

    public function label(): string
    {
        return __(match ($this) {
            self::PurchaseOrder => 'Purchase Order',
            self::PurchaseInvoice => 'Purchase Invoice',
            self::PurchaseReturn => 'Purchase Return',
            self::ForeignPurchaseInvoice => 'Foreign Purchase Invoice',
            self::CashPurchaseInvoice => 'Cash Purchase Invoice',
            self::CashPurchaseReturn => 'Cash Purchase Return',
        });
    }

    public function isPostable(): bool
    {
        return $this !== self::PurchaseOrder;
    }

    public function isReturn(): bool
    {
        return in_array($this, [self::PurchaseReturn, self::CashPurchaseReturn], true);
    }

    public function isCash(): bool
    {
        return in_array($this, [self::CashPurchaseInvoice, self::CashPurchaseReturn], true);
    }

    public function stockDirection(): int
    {
        return match (true) {
            ! $this->isPostable() => 0,
            $this->isReturn() => -1,
            default => 1,
        };
    }
}
