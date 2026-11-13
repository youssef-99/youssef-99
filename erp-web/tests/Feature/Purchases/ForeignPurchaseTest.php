<?php

namespace Tests\Feature\Purchases;

use App\Models\Account;
use App\Models\Accreditation;
use App\Models\AccreditationFee;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Currency;
use App\Services\AccreditationService;
use App\Services\PurchaseDocumentService;
use App\Services\Settings;
use Tests\ErpTestCase;
use Tests\Feature\Purchases\Concerns\CreatesPurchaseFixtures;

class ForeignPurchaseTest extends ErpTestCase
{
    use CreatesPurchaseFixtures;

    public function test_shipping_and_expenses_are_allocated_into_inventory_cost_by_value(): void
    {
        $supplier = $this->makeSupplier(['supplier_type' => 'foreign']);
        $cheap = $this->makeItem(['vat_rate' => 0]);
        $dear = $this->makeItem(['vat_rate' => 0, 'gtin' => null]);

        $doc = $this->makePurchaseDocument('foreign_purchase_invoice', [
            'supplier_id' => $supplier->id, 'account_id' => $supplier->account_id,
            'currency_id' => Currency::query()->where('iso_code', 'USD')->value('id'), 'exchange_rate' => 3.75,
            'shipping_cost' => 40, 'purchase_expenses' => 60,
        ], [
            ['item_id' => $cheap->id, 'quantity' => 10, 'unit_price' => 10, 'tax_percent' => 0],
            ['item_id' => $dear->id, 'quantity' => 10, 'unit_price' => 30, 'tax_percent' => 0],
        ]);
        PurchaseDocumentService::post($doc);

        // goods 400 USD = 1500 SAR; expenses 100 USD = 375 SAR split 25% / 75%
        $this->assertEquals(round((375 + 93.75) / 10, 4), (float) $cheap->refresh()->average_cost);
        $this->assertEquals(round((1125 + 281.25) / 10, 4), (float) $dear->refresh()->average_cost);

        $entry = $this->entryByAccount($doc->journalEntry);
        $this->assertEquals(1875, $entry['110301']);
        $this->assertEquals(-1500, $entry[$supplier->account->number]);
        $this->assertEquals(-375, $entry['520104']);
    }

    public function test_letter_of_credit_fee_and_invoice_post_to_the_lc_account(): void
    {
        $supplier = $this->makeSupplier(['supplier_type' => 'foreign']);
        $item = $this->makeItem(['vat_rate' => 0]);
        $bankAccount = Account::query()->where('number', '110103')->value('id');
        $lcAccount = Account::query()->where('number', '210301')->value('id');
        $bank = Bank::query()->create(['code' => '9', 'name_ar' => 'بنك', 'account_id' => $bankAccount]);
        $lc = Accreditation::query()->create([
            'number' => 'LC-1', 'bank_id' => $bank->id, 'lc_account_id' => $lcAccount, 'inventory_account_id' => Settings::account('inventory'),
            'supplier_id' => $supplier->id, 'value' => 10000, 'open_date' => now()->toDateString(),
        ]);

        $fee = AccreditationFee::query()->create([
            'accreditation_id' => $lc->id, 'branch_id' => Branch::query()->value('id'), 'date' => now()->toDateString(), 'fee_type' => 'customs', 'amount' => 500,
        ]);
        AccreditationService::post($fee);
        $this->assertTrue($fee->refresh()->is_posted);
        $feeEntry = $this->entryByAccount($fee->journalEntry);
        $this->assertEquals(500, $feeEntry['210301']);
        $this->assertEquals(-500, $feeEntry['110103']);
        $this->assertEquals(500.0, AccreditationService::feesTotal($lc));

        $doc = $this->makePurchaseDocument('foreign_purchase_invoice', [
            'supplier_id' => $supplier->id, 'account_id' => $supplier->account_id, 'accreditation_id' => $lc->id,
            'exchange_rate' => 1, 'purchase_expenses' => 500,
        ], [
            ['item_id' => $item->id, 'quantity' => 100, 'unit_price' => 20, 'tax_percent' => 0],
        ]);
        PurchaseDocumentService::post($doc);

        $entry = $this->entryByAccount($doc->journalEntry);
        $this->assertEquals(2500, $entry['110301']);
        $this->assertEquals(-2500, $entry['210301']);
        $this->assertArrayNotHasKey($supplier->account->number, $entry);
        $this->assertEquals(25, (float) $item->refresh()->average_cost);

        AccreditationService::close($lc);
        $this->assertFalse($lc->refresh()->isOpen());
        $this->assertNotNull($lc->close_date);
    }
}
