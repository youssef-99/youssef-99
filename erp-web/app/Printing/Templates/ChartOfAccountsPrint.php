<?php

namespace App\Printing\Templates;

use App\Models\Account;
use App\Printing\PrintTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Chart of accounts (دليل الحسابات): the account tree indented by level. The route id is ignored.
 */
class ChartOfAccountsPrint extends PrintTemplate
{
    public function find(int $id): Model
    {
        return new Account;
    }

    public function view(Model $record, Request $request): string
    {
        return 'print.chart-of-accounts';
    }

    public static function natureLabel(?string $nature): string
    {
        return $nature === 'credit' ? __('Credit Nature') : __('Debit Nature');
    }

    /**
     * Accounts in tree order (depth first, children sorted by number) with their depth.
     *
     * @return array<int, array{account: Account, depth: int}>
     */
    public static function tree(): array
    {
        $accounts = Account::query()->orderBy('number')->get();
        $byParent = $accounts->groupBy(fn (Account $account): int => (int) $account->parent_id);
        $ids = $accounts->pluck('id')->flip();
        $rows = [];

        $walk = function (Collection $nodes, int $depth) use (&$walk, &$rows, $byParent): void {
            foreach ($nodes as $node) {
                $rows[] = ['account' => $node, 'depth' => $depth];
                $walk($byParent->get($node->id, collect()), $depth + 1);
            }
        };

        $roots = $accounts->filter(fn (Account $account): bool => ! $account->parent_id || ! $ids->has($account->parent_id));
        $walk($roots, 0);

        return $rows;
    }

    public function build(Model $record, Request $request): array
    {
        return [
            'title' => __('Chart of Accounts'),
            'number' => null,
            'tree' => static::tree(),
        ];
    }
}
