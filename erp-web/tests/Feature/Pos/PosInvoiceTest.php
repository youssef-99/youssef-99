<?php

namespace Tests\Feature\Pos;

use App\Exceptions\ErpException;
use App\Filament\Resources\Pos\PosSalesInvoiceResource;
use App\Filament\Resources\Pos\PosSalesInvoiceResource\Pages\CreatePosSalesInvoice;
use App\Models\SalesDocument;
use App\Models\StockMovement;
use App\Services\PosService;
use App\Services\Settings;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class PosInvoiceTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
    }

    public function test_pos_pages_render(): void
    {
        $doc = SalesDocument::query()->where('type', 'pos_invoice')->firstOrFail();

        $this->get(PosSalesInvoiceResource::getUrl('index'))->assertOk()->assertSee($doc->number);
        $this->get(PosSalesInvoiceResource::getUrl('create'))->assertOk();
        $this->get(PosSalesInvoiceResource::getUrl('view', ['record' => $doc]))->assertOk();
    }

    public function test_pos_invoice_saved_and_posted_through_the_form_with_cash_and_card(): void
    {
        $item = $this->item('1005'); // 29.00 + 15% VAT, standard item
        $before = StockService::balance($item->id);

        Livewire::test(CreatePosSalesInvoice::class)
            ->set('data.scan', $item->barcode)
            ->set('data.scan', $item->barcode)
            ->set('data.card_amount', 40)
            ->set('data.cash_amount', 50)
            ->set('data.post_now', true)
            ->assertSet('data.grand_total', 66.7)
            ->assertSet('data.change_due', 23.3)
            ->call('create')
            ->assertHasNoFormErrors();

        $doc = SalesDocument::query()->where('type', 'pos_invoice')->latest('id')->firstOrFail();
        $this->assertTrue($doc->is_posted);
        $this->assertEquals(58.00, (float) $doc->net_total);
        $this->assertEquals(8.70, (float) $doc->tax_total);
        $this->assertEquals(66.70, (float) $doc->grand_total);
        $this->assertSame('mixed', $doc->payment_method);
        $this->assertEquals(23.30, (float) $doc->change_due);
        $this->assertTrue($doc->is_paid);
        $this->assertNotNull($doc->time);

        $entry = $doc->journalEntry;
        $this->assertEquals($entry->total_debit, $entry->total_credit);
        $this->assertSame(['debit' => 40.0, 'credit' => 0.0], $this->accountTotals($entry, 'card'));
        $this->assertSame(['debit' => 26.7, 'credit' => 0.0], $this->accountTotals($entry, 'cash'));
        $this->assertSame(58.0, $this->accountTotals($entry, 'sales')['credit']);
        $this->assertSame(8.7, $this->accountTotals($entry, 'vat_output')['credit']);
        $cost = round(2 * StockService::unitCost($item), 2);
        $this->assertSame($cost, $this->accountTotals($entry, 'cogs')['debit']);
        $this->assertSame($cost, $this->accountTotals($entry, 'inventory')['credit']);

        $this->assertEquals($before - 2, StockService::balance($item->id));
        $this->assertSame(1, StockMovement::query()->where('source_type', $doc->getMorphClass())->where('source_id', $doc->id)->count());
        $this->assertSame('simplified', $doc->zatca_invoice_type);
    }

    public function test_print_invoice_redirects_to_the_80mm_receipt(): void
    {
        $item = $this->item('1006');

        $component = Livewire::test(CreatePosSalesInvoice::class)
            ->set('data.scan', $item->code)
            ->set('data.cash_amount', 20)
            ->set('data.post_now', true)
            ->set('data.print_invoice', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $doc = SalesDocument::query()->where('type', 'pos_invoice')->latest('id')->firstOrFail();
        $component->assertRedirect(route('print', ['type' => 'sales', 'id' => $doc->id]).'?layout=80mm');
    }

    public function test_change_due_is_received_minus_cash_due(): void
    {
        $payment = PosService::payment(100, 0, 30, 100);

        $this->assertSame(70.0, $payment['cash_due']);
        $this->assertSame(30.0, $payment['change_due']);
        $this->assertSame('mixed', $payment['payment_method']);
        $this->assertTrue($payment['is_paid']);
        $this->assertSame('card', PosService::payment(50, 0, 50)['payment_method']);
    }

    public function test_underpayment_is_rejected(): void
    {
        $doc = $this->draftPos(['1005' => 1], ['cash_amount' => 10]);

        try {
            PosService::post($doc);
            $this->fail('Underpayment was accepted.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('23.35', $e->getMessage());
        }

        $this->assertFalse($doc->refresh()->is_posted);
        $this->assertNull($doc->journal_entry_id);
    }

    public function test_card_cannot_exceed_total(): void
    {
        $doc = $this->draftPos(['1005' => 1], ['card_amount' => 50]);

        $this->expectException(ErpException::class);
        PosService::post($doc);
    }

    public function test_form_blocks_posting_when_underpaid(): void
    {
        $count = SalesDocument::query()->count();

        Livewire::test(CreatePosSalesInvoice::class)
            ->set('data.scan', $this->item('1005')->barcode)
            ->set('data.cash_amount', 5)
            ->set('data.post_now', true)
            ->call('create');

        $this->assertSame($count, SalesDocument::query()->count());
    }

    public function test_unpost_restores_stock_and_removes_the_entry(): void
    {
        Settings::set('zatca.enabled', '0');
        $item = $this->item('1005');
        $before = StockService::balance($item->id);
        $doc = PosService::post($this->draftPos(['1005' => 3], ['cash_amount' => 200]));
        $this->assertEquals($before - 3, StockService::balance($item->id));

        PosService::unpost($doc);

        $this->assertFalse($doc->refresh()->is_posted);
        $this->assertEquals($before, StockService::balance($item->id));
    }
}
