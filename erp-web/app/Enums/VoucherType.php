<?php

namespace App\Enums;

/**
 * Cash box / cheque vouchers. The header account is the cash/bank side;
 * lines are the counter accounts.
 */
enum VoucherType: string
{
    case ReceiptCash = 'receipt_cash';
    case ReceiptCheque = 'receipt_cheque';
    case PaymentCash = 'payment_cash';
    case PaymentCheque = 'payment_cheque';
    case CreditNotice = 'credit_notice';
    case DebitNotice = 'debit_notice';

    public function label(): string
    {
        return __(match ($this) {
            self::ReceiptCash => 'Receipt Voucher',
            self::ReceiptCheque => 'Cheque Receipt Voucher',
            self::PaymentCash => 'Cash Payment Voucher',
            self::PaymentCheque => 'Cheque Payment Voucher',
            self::CreditNotice => 'Credit Notice',
            self::DebitNotice => 'Debit Notice',
        });
    }

    /** true when the header account is debited (money/value comes in to the header account). */
    public function headerIsDebit(): bool
    {
        return in_array($this, [self::ReceiptCash, self::ReceiptCheque, self::DebitNotice], true);
    }

    public function entryType(): string
    {
        return match ($this) {
            self::ReceiptCash, self::ReceiptCheque => 'receipt',
            self::PaymentCash, self::PaymentCheque => 'payment',
            self::CreditNotice, self::DebitNotice => 'notice',
        };
    }
}
