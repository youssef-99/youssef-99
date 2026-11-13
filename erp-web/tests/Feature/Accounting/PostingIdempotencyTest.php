<?php

namespace Tests\Feature\Accounting;

use App\Enums\StockDocumentType;
use App\Exceptions\ErpException;
use App\Models\Account;
use App\Models\ChequeCollection;
use App\Models\JournalEntry;
use App\Models\PurchaseDocument;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\Voucher;
use App\Services\ManualJournalService;
use App\Services\PurchaseDocumentService;
use App\Services\StockDocumentService;
use App\Services\VoucherService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Tests\ErpTestCase;
use Tests\Feature\Inventory\Concerns\CreatesInventory;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

/**
 * Posting re-reads the document under a row lock: a second request holding a stale copy of an
 * already posted (or unposted) document is refused instead of posting it twice.
 */
class PostingIdempotencyTest extends ErpTestCase
{
    use CreatesInventory, CreatesPurchaseFixtures {
        CreatesInventory::makeItem insteadof CreatesPurchaseFixtures;
        CreatesPurchaseFixtures::makeItem as makePurchaseItem;
    }

    private function accountId(string $number): int
    {
        return (int) Account::query()->where('number', $number)->value('id');
    }

    /** Post through one copy, then try again through a stale copy loaded before the first post. */
    private function assertStaleCopyIsRefused(Model $record, Closure $action): void
    {
        $stale = $record->newQuery()->findOrFail($record->getKey());
        $action($record);

        try {
            $action($stale);
            $this->fail('A stale copy was processed a second time.');
        } catch (ErpException) {
            $this->assertTrue(true);
        }
    }

    public function test_stock_document_cannot_be_posted_twice_from_a_stale_copy(): void
    {
        $item = $this->makeItem('PI1');
        $document = $this->stockDocument(StockDocumentType::PositiveAdjustment, [['item_id' => $item->id, 'quantity' => 3, 'price' => 4]]);

        $this->assertStaleCopyIsRefused($document, fn (StockDocument $d) => StockDocumentService::post($d));

        $this->assertSame(1, StockMovement::query()->where('source_id', $document->id)->count());
        $this->assertEquals(4, (float) $item->refresh()->average_cost);
    }

    public function test_purchase_invoice_cannot_be_posted_or_unposted_twice(): void
    {
        $supplier = $this->makeSupplier();
        $item = $this->makePurchaseItem();
        $invoice = $this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 10, 'tax_percent' => 15],
        ]);

        $this->assertStaleCopyIsRefused($invoice, fn (PurchaseDocument $d) => PurchaseDocumentService::post($d));
        $this->assertSame(1, JournalEntry::query()->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)->count());

        $this->assertStaleCopyIsRefused($invoice->refresh(), fn (PurchaseDocument $d) => PurchaseDocumentService::unpost($d));
    }

    public function test_voucher_and_manual_entry_cannot_be_posted_twice(): void
    {
        $voucher = Voucher::query()->create(['type' => 'receipt_cash', 'branch_id' => 1, 'date' => now(), 'account_id' => $this->accountId('110101')]);
        $voucher->lines()->create(['account_id' => $this->accountId('420101'), 'amount' => 50]);
        $this->assertStaleCopyIsRefused($voucher, fn (Voucher $v) => VoucherService::post($v));

        $entry = JournalEntry::query()->create(['branch_id' => 1, 'date' => now()]);
        $entry->lines()->create(['account_id' => $this->accountId('110101'), 'debit' => 10]);
        $entry->lines()->create(['account_id' => $this->accountId('420101'), 'credit' => 10]);
        $this->assertStaleCopyIsRefused($entry, fn (JournalEntry $e) => ManualJournalService::post($e));
    }

    public function test_cheques_due_on_the_last_day_are_collectable_and_collected_once(): void
    {
        $voucher = Voucher::query()->create(['type' => 'receipt_cheque', 'branch_id' => 1, 'date' => '2026-03-01']);
        $voucher->lines()->create(['account_id' => $this->accountId('420101'), 'amount' => 75, 'due_date' => '2026-03-31', 'cheque_no' => 'LAST']);
        VoucherService::post($voucher);

        $this->assertCount(1, VoucherService::collectableCheques(1, '2026-03-01', '2026-03-31'));

        $attributes = ['branch_id' => 1, 'date' => '2026-03-31', 'from_date' => '2026-03-01', 'to_date' => '2026-03-31', 'bank_account_id' => $this->accountId('110103')];
        $collection = ChequeCollection::query()->create($attributes);
        $this->assertStaleCopyIsRefused($collection, fn (ChequeCollection $c) => VoucherService::postCollection($c));

        $other = ChequeCollection::query()->create($attributes);
        $this->expectException(ErpException::class);
        VoucherService::postCollection($other);
    }
}
