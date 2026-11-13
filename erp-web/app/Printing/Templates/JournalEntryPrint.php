<?php

namespace App\Printing\Templates;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Printing\PrintPermissions;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Journal vouchers (manual and generated entries) with debit / credit totals.
 */
class JournalEntryPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return JournalEntry::query()
            ->with(['branch.company', 'creator', 'lines.account', 'lines.costCenter', 'lines.salesman'])
            ->findOrFail($id);
    }

    /** Users who can view the source document may print its entry too. */
    public function permissionKeys(Model $record): array
    {
        /** @var JournalEntry $record */
        $keys = PrintPermissions::keysFor($record);
        $source = $record->source_type ? rescue(fn () => $record->source, null, false) : null;

        return $source instanceof Model ? array_values(array_unique([...$keys, ...PrintPermissions::keysFor($source)])) : $keys;
    }

    public static function entryTypeLabel(?string $entryType): string
    {
        return __(match ($entryType) {
            'manual' => 'Manual Entry',
            'opening' => 'Opening Entry',
            'sales' => 'Sales Entry',
            'sales_return' => 'Sales Return Entry',
            'pos' => 'POS Entry',
            'purchase' => 'Purchase Entry',
            'purchase_return' => 'Purchase Return Entry',
            'receipt' => 'Receipt Entry',
            'payment' => 'Payment Entry',
            'notice' => 'Notice Entry',
            'inventory' => 'Inventory Entry',
            'cheque_collection' => 'Cheque Collection Entry',
            'closing' => 'Closing Entry',
            default => Str::headline((string) $entryType),
        });
    }

    public function build(Model $record, Request $request): array
    {
        /** @var JournalEntry $record */
        $rows = $record->lines->values()->map(fn (JournalEntryLine $line, int $index): array => [
            'no' => $index + 1,
            'account_no' => $line->account?->number,
            'account' => $line->account?->name,
            'description' => $line->description,
            'cost_center' => $line->costCenter?->name,
            'document_no' => $line->document_no,
            'debit' => (float) $line->debit ? static::money($line->debit) : '',
            'credit' => (float) $line->credit ? static::money($line->credit) : '',
        ])->all();

        $debit = (float) $record->lines->sum('debit');
        $credit = (float) $record->lines->sum('credit');

        return [
            'title' => __('Journal Voucher'),
            'subtitle' => static::entryTypeLabel($record->entry_type),
            'status' => $this->statusLabel($record),
            'number' => $record->number,
            'fields' => $this->fields([
                ['Voucher No.', $record->number],
                ['Voucher Date', static::date($record->date)],
                ['Branch', $record->branch?->name],
                ['Entry Type', static::entryTypeLabel($record->entry_type)],
                ['Prepared By', $record->creator?->name],
            ]),
            'partyTitle' => null,
            'partyFields' => [],
            'columns' => [
                'no' => ['label' => __('No.'), 'align' => 'start'],
                'account_no' => ['label' => __('Account No.'), 'align' => 'start'],
                'account' => ['label' => __('Account Name'), 'align' => 'start'],
                'description' => ['label' => __('Line Description'), 'align' => 'start'],
                'cost_center' => ['label' => __('Cost Center'), 'align' => 'start'],
                'document_no' => ['label' => __('Document No.'), 'align' => 'start'],
                'debit' => ['label' => __('Debit Amount'), 'align' => 'end'],
                'credit' => ['label' => __('Credit Amount'), 'align' => 'end'],
            ],
            'rows' => $rows,
            'footerRow' => ['account' => __('Total'), 'debit' => static::money($debit), 'credit' => static::money($credit)],
            'totals' => [
                [__('Total Debit'), static::money($debit), false],
                [__('Total Credit'), static::money($credit), false],
                [__('Difference'), static::money(round($debit - $credit, 2)), true],
            ],
            'grandTotal' => null,
            'amountInWords' => null,
            'notes' => $record->description,
            'qr' => null,
            'signatures' => [__('Accountant'), __('Reviewed By'), __('Financial Manager')],
            'record' => $record,
        ];
    }
}
