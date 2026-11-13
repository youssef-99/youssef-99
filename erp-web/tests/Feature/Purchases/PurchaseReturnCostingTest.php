<?php

namespace Tests\Feature\Purchases;

use App\Exceptions\ErpException;
use App\Models\PurchaseDocument;
use App\Models\StockMovement;
use App\Services\CashTransactionService;
use App\Services\PurchaseDocumentService;
use App\Services\StockService;
use Tests\ErpTestCase;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

/**
 * Purchase returns: bonus units apart from paid units, stock leaves at its landed cost, and
 * purchased services are expensed instead of capitalised.
 */
class PurchaseReturnCostingTest extends ErpTestCase
{
    use CreatesPurchaseFixtures;

    /** 10 paid + 2 bonus at 12 => landed unit cost 10. */
    protected function postedInvoiceWithBonus(array $attributes = []): PurchaseDocument
    {
        $supplier = $this->makeSupplier();
        $item = $this->makeItem();
        $invoice = $this->makePurchaseDocument('purchase_invoice', $attributes + ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $item->id, 'quantity' => 10, 'bonus_qty' => 2, 'unit_price' => 12, 'tax_percent' => 15],
        ]);

        return PurchaseDocumentService::post($invoice);
    }

    /** @param array<string, mixed> $line */
    protected function returnFor(PurchaseDocument $invoice, array $line): PurchaseDocument
    {
        $itemId = $invoice->lines()->value('item_id');

        return $this->makePurchaseDocument('purchase_return', [
            'supplier_id' => $invoice->supplier_id, 'account_id' => $invoice->account_id,
            'reference_type' => 'invoice', 'original_document_id' => $invoice->id,
        ], [$line + ['item_id' => $itemId, 'unit_price' => 12, 'tax_percent' => 15]]);
    }

    public function test_bonus_units_cannot_be_returned_as_paid_units(): void
    {
        $invoice = $this->postedInvoiceWithBonus();
        $this->assertEquals(['quantity' => 10.0, 'bonus' => 2.0], PurchaseDocumentService::returnableDetail($invoice)[$invoice->lines()->value('item_id')]);

        try {
            PurchaseDocumentService::post($this->returnFor($invoice, ['quantity' => 12]));
            $this->fail('Twelve paid units were returned against ten paid + two bonus.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('10', $e->getMessage());
        }

        $this->expectException(ErpException::class);
        PurchaseDocumentService::post($this->returnFor($invoice, ['quantity' => 10, 'bonus_qty' => 3]));
    }

    public function test_return_credits_inventory_at_the_landed_cost_and_books_the_difference(): void
    {
        $invoice = $this->postedInvoiceWithBonus();
        $return = PurchaseDocumentService::post($this->returnFor($invoice, ['quantity' => 5]));

        $entry = $this->entryByAccount($return->journalEntry);
        $this->assertEquals(-50, $entry['110301'], 'Five units at the landed cost of 10.');
        $this->assertEquals(-9, $entry['110501']);
        $this->assertEquals(69, $entry[$invoice->supplier->account->number]);
        $this->assertEquals(-10, $entry['420101'], 'Refund above the stock value goes to the purchase discount account.');

        $movement = StockMovement::query()->where('source_type', $return->getMorphClass())->where('source_id', $return->id)->sole();
        $this->assertEquals(10, (float) $movement->unit_cost);
        $this->assertEquals(7, StockService::balance($movement->item_id));
    }

    public function test_returning_paid_units_with_their_bonus_needs_no_difference_line(): void
    {
        $invoice = $this->postedInvoiceWithBonus();
        $return = PurchaseDocumentService::post($this->returnFor($invoice, ['quantity' => 10, 'bonus_qty' => 2]));

        $entry = $this->entryByAccount($return->journalEntry);
        $this->assertEquals(-120, $entry['110301']);
        $this->assertArrayNotHasKey('420101', $entry);
    }

    public function test_copied_return_keeps_the_bonus_split_and_the_additional_discount(): void
    {
        $invoice = $this->postedInvoiceWithBonus(['additional_discount' => 20]);
        $return = PurchaseDocument::query()->create([
            'type' => 'purchase_return', 'branch_id' => $invoice->branch_id, 'warehouse_id' => $invoice->warehouse_id,
            'date' => now()->toDateString(), 'supplier_id' => $invoice->supplier_id, 'account_id' => $invoice->account_id,
            'reference_type' => 'invoice', 'original_document_id' => $invoice->id,
        ]);

        CashTransactionService::copyOriginalLines($return);

        $line = $return->refresh()->lines()->sole();
        $this->assertEquals(10, (float) $line->quantity);
        $this->assertEquals(2, (float) $line->bonus_qty);
        $this->assertEquals(20, (float) $return->additional_discount);
        $this->assertEquals((float) $invoice->grand_total, (float) $return->grand_total);

        PurchaseDocumentService::post($return);
        $entry = $this->entryByAccount($return->refresh()->journalEntry);
        $this->assertEquals(-100, $entry['110301'], 'A full return gives back exactly what the invoice capitalised.');
        $this->assertArrayNotHasKey('420101', $entry);
    }

    public function test_purchased_service_lines_are_expensed_not_capitalised(): void
    {
        $supplier = $this->makeSupplier();
        $goods = $this->makeItem();
        $service = $this->makeItem(['item_type' => 'service', 'item_nature' => 'service', 'gtin' => null]);
        $invoice = PurchaseDocumentService::post($this->makePurchaseDocument('purchase_invoice', ['supplier_id' => $supplier->id, 'account_id' => $supplier->account_id], [
            ['item_id' => $goods->id, 'quantity' => 2, 'unit_price' => 50, 'tax_percent' => 15],
            ['item_id' => $service->id, 'quantity' => 1, 'unit_price' => 30, 'tax_percent' => 15],
        ]));

        $entry = $this->entryByAccount($invoice->journalEntry);
        $this->assertEquals(100, $entry['110301']);
        $this->assertEquals(30, $entry['510101'], 'Falls back to cost of goods sold while no services expense account is set.');
        $this->assertEquals(19.5, $entry['110501']);
        $this->assertEquals(0, StockService::balance($service->id));
    }
}
