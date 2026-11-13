<?php

namespace App\Services;

use App\Exceptions\ErpException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntryLine;
use App\Models\VoucherLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Customer master data (العملاء): sub-ledger account and opening balance, deletion rules,
 * attachments and the XLSX export.
 */
class CustomerService
{
    /** Disk the customer documents (وثيقة العميل) are stored on. */
    public const ATTACHMENTS_DISK = 'local';

    public const ATTACHMENTS_DIRECTORY = 'customers';

    /** Create / rename the customer's sub-ledger account and refresh its opening-balance entry. */
    public static function afterSave(Customer $customer): Customer
    {
        DB::transaction(fn () => PartyAccountService::sync($customer));

        return $customer->refresh();
    }

    /** Why the customer cannot be deleted, or null when it can. */
    public static function deletionBlocker(Customer $customer): ?string
    {
        if ($customer->salesDocuments()->exists()) {
            return __('The customer has sales documents and cannot be deleted.');
        }

        if ($customer->account_id) {
            $hasMovements = JournalEntryLine::query()
                ->where('account_id', $customer->account_id)
                ->whereHas('journalEntry', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('source_type', '!=', $customer->getMorphClass())
                    ->orWhere('source_id', '!=', $customer->getKey())
                    ->orWhereNull('source_type')))
                ->exists();

            if ($hasMovements || VoucherLine::query()->where('account_id', $customer->account_id)->exists()) {
                return __('The customer account has journal entries and cannot be deleted.');
            }
        }

        return null;
    }

    /** Delete a customer with its opening entry, attachments and (unused) sub-ledger account. */
    public static function delete(Customer $customer): void
    {
        if ($reason = static::deletionBlocker($customer)) {
            throw new ErpException($reason);
        }

        DB::transaction(function () use ($customer) {
            $accountId = $customer->account_id;
            JournalService::removeFor($customer);
            $customer->delete();

            if ($accountId && ($account = Account::query()->find($accountId)) && $account->type === 'customer') {
                try {
                    DB::transaction(fn () => $account->delete());
                } catch (QueryException) {
                    $account->update(['is_active' => false]);
                }
            }
        });

        foreach ($customer->attachments ?? [] as $path) {
            Storage::disk(static::ATTACHMENTS_DISK)->delete($path);
        }
    }

    /**
     * Attachments with their original names and a temporary download URL.
     *
     * @return array<int, array{name: string, path: string, url: ?string}>
     */
    public static function attachments(Customer $customer): array
    {
        $disk = Storage::disk(static::ATTACHMENTS_DISK);
        $names = $customer->attachment_names ?? [];

        return collect($customer->attachments ?? [])
            ->filter(fn ($path) => is_string($path) && $path !== '')
            ->map(fn (string $path) => [
                'name' => (string) ($names[$path] ?? basename($path)),
                'path' => $path,
                'url' => $disk->exists($path) ? $disk->temporaryUrl($path, now()->addMinutes(30)) : null,
            ])
            ->values()
            ->all();
    }

    /** The columns of the customers list export. */
    public static function exportHeadings(): array
    {
        return array_map(fn (string $h) => __($h), [
            'Customer Code', 'Customer Name', 'Customer Group', 'Salesman', 'Commercial Register', 'VAT No.',
            'City', 'Mobile', 'Account No.', 'Credit Limit', 'Payment Term', 'Active?',
        ]);
    }

    /** Write the customers of $query to an XLSX file (php://output for a download). */
    public static function exportXlsx(Builder $query, string $path = 'php://output'): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(static::exportHeadings()));

        $query->reorder()->with(['customerGroup', 'salesman', 'city', 'account'])->lazyById(500)->each(function (Customer $c) use ($writer) {
            $writer->addRow(Row::fromValues([
                (string) $c->code,
                $c->name,
                $c->customerGroup?->name,
                $c->salesman?->name,
                $c->commercial_register,
                $c->vat_no,
                $c->city?->name,
                $c->mobile,
                $c->account?->number,
                (float) $c->credit_limit,
                (int) $c->payment_term_days,
                $c->is_active ? __('Yes') : __('No'),
            ]));
        });

        $writer->close();
    }
}
