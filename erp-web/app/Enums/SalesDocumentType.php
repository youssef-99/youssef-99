<?php

namespace App\Enums;

enum SalesDocumentType: string
{
    case Quotation = 'quotation';
    case CustomerOrder = 'customer_order';
    case SalesInvoice = 'sales_invoice';
    case SalesReturn = 'sales_return';
    case PosInvoice = 'pos_invoice';
    case PosReturn = 'pos_return';
    case CashSalesInvoice = 'cash_sales_invoice';
    case CashSalesReturn = 'cash_sales_return';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    public function label(): string
    {
        return __(match ($this) {
            self::Quotation => 'Quotation',
            self::CustomerOrder => 'Customer Order',
            self::SalesInvoice => 'Sales Invoice',
            self::SalesReturn => 'Sales Return',
            self::PosInvoice => 'POS Sales Invoice',
            self::PosReturn => 'POS Sales Return',
            self::CashSalesInvoice => 'Cash Sales Invoice',
            self::CashSalesReturn => 'Cash Sales Return',
            self::CreditNote => 'Credit Note',
            self::DebitNote => 'Debit Note',
        });
    }

    /** Does posting this document move stock / create a journal entry? */
    public function isPostable(): bool
    {
        return ! in_array($this, [self::Quotation, self::CustomerOrder], true);
    }

    public function isReturn(): bool
    {
        return in_array($this, [self::SalesReturn, self::PosReturn, self::CashSalesReturn, self::CreditNote], true);
    }

    /** ZATCA credit / debit notes (إشعار دائن / مدين): journal only, no stock (see ZatcaNoteService). */
    public function isNote(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote], true);
    }

    /** The return document type of an invoice type (sales_invoice => sales_return ...), null for other types. */
    public function returnType(): ?self
    {
        return match ($this) {
            self::SalesInvoice => self::SalesReturn,
            self::PosInvoice => self::PosReturn,
            self::CashSalesInvoice => self::CashSalesReturn,
            default => null,
        };
    }

    public function isPos(): bool
    {
        return in_array($this, [self::PosInvoice, self::PosReturn], true);
    }

    public function isCash(): bool
    {
        return in_array($this, [self::CashSalesInvoice, self::CashSalesReturn], true);
    }

    /** +1 stock in, -1 stock out, 0 none */
    public function stockDirection(): int
    {
        return match (true) {
            $this->isNote() => 0,
            ! $this->isPostable() => 0,
            $this->isReturn() => 1,
            default => -1,
        };
    }

    /** ZATCA-reportable documents. */
    public function isZatca(): bool
    {
        return $this->isPostable();
    }
}
