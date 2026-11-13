<?php

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Models\Branch;
use App\Services\JournalService;
use Tests\ErpTestCase;

/**
 * JournalService::balance() date boundaries and line amounts.
 */
class JournalBalanceTest extends ErpTestCase
{
    public function test_balance_includes_entries_dated_on_the_to_day_and_keeps_line_amounts(): void
    {
        [$cash, $sales] = Account::query()->where('is_postable', true)->orderBy('code')->take(2)->get()->all();
        $branchId = (int) Branch::query()->value('id');

        JournalService::record($this->admin, 'test', $branchId, '2026-03-10', [
            ['account_id' => $cash->id, 'debit' => 100],
            ['account_id' => $sales->id, 'credit' => 100],
        ]);

        $onDay = JournalService::balance($cash->id, '2026-03-10', '2026-03-10');
        $this->assertSame(100.0, $onDay['debit']);
        $this->assertSame(0.0, $onDay['opening']);

        $after = JournalService::balance($cash->id, '2026-03-11', '2026-03-31');
        $this->assertSame(100.0, $after['opening']);
        $this->assertSame(0.0, $after['debit']);

        $before = JournalService::balance($cash->id, '2026-03-01', '2026-03-09');
        $this->assertSame(0.0, $before['debit']);
    }
}
