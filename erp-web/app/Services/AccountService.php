<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\ErpException;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Support\SafeSpreadsheetRow;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Chart of accounts (دليل الحسابات): delete protection and Excel export.
 */
class AccountService
{
    /** Accounts that have children, journal lines or are referenced by vouchers/banks cannot be deleted. */
    public static function assertDeletable(Account $account): void
    {
        if ($account->children()->exists()) {
            throw new ErpException(__('Account :number has sub-accounts and cannot be deleted.', ['number' => $account->number]));
        }

        $isUsed = $account->journalLines()->exists()
            || Voucher::query()->where('account_id', $account->id)->exists()
            || VoucherLine::query()->where('account_id', $account->id)->exists()
            || Bank::query()->where('account_id', $account->id)->exists();

        if ($isUsed) {
            throw new ErpException(__('Account :number has transactions and cannot be deleted.', ['number' => $account->number]));
        }

        $defaultKeys = array_keys(Settings::ACCOUNT_KEYS);
        foreach ($defaultKeys as $key) {
            if ((int) Settings::get($key) === $account->id) {
                throw new ErpException(__('Account :number is a default posting account and cannot be deleted.', ['number' => $account->number]));
            }
        }
    }

    public static function delete(Account $account): void
    {
        static::assertDeletable($account);
        $account->delete();
    }

    /**
     * Rows of the chart of accounts ordered by account number.
     *
     * @return array<int, array{number: string, name_ar: string, name_en: ?string, level: int, parent: ?string, nature: string, type: ?string, balance_sheet_item: ?string, is_postable: bool}>
     */
    public static function chartRows(): array
    {
        $accounts = Account::query()->with('parent')->orderBy('number')->get();

        return $accounts->map(fn (Account $account): array => [
            'number' => (string) $account->number,
            'name_ar' => (string) $account->name_ar,
            'name_en' => $account->name_en,
            'level' => (int) $account->level,
            'parent' => $account->parent?->number,
            'nature' => AccountType::natureOptions()[$account->nature] ?? $account->nature,
            'type' => AccountType::labelFor($account->type),
            'balance_sheet_item' => AccountType::balanceSheetOptions()[$account->balance_sheet_item] ?? $account->balance_sheet_item,
            'is_postable' => (bool) $account->is_postable,
        ])->all();
    }

    /** Write the chart of accounts as an XLSX file (path may be php://output). */
    public static function writeChartXlsx(string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(SafeSpreadsheetRow::fromValues([
            __('Account No.'),
            __('Account Name').' ('.__('Arabic').')',
            __('Account Name').' ('.__('English').')',
            __('Level No.'),
            __('Parent Account'),
            __('Account Nature'),
            __('Account Type'),
            __('Balance Sheet Item'),
            __('Postable?'),
        ], (new Style)->setFontBold()));

        foreach (static::chartRows() as $row) {
            $writer->addRow(SafeSpreadsheetRow::fromValues([
                $row['number'],
                $row['name_ar'],
                $row['name_en'],
                $row['level'],
                $row['parent'],
                $row['nature'],
                $row['type'],
                $row['balance_sheet_item'],
                $row['is_postable'] ? __('Yes') : __('No'),
            ]));
        }

        $writer->close();
    }
}
