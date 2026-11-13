<?php

namespace Tests\Feature\Pos;

use App\Exceptions\ErpException;
use App\Filament\Resources\Pos\PosSalesReturnResource;
use App\Filament\Resources\Pos\PosSalesReturnResource\Pages\CreatePosSalesReturn;
use App\Models\SalesDocument;
use App\Services\PosService;
use App\Services\StockService;
use Livewire\Livewire;
use Tests\ErpTestCase;

class PosReturnTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
    }

    public function test_return_form_copies_original_lines_and_posts_a_refund(): void
    {
        $invoice = PosService::post($this->draftPos(['1005' => 3, '1006' => 2], ['card_amount' => 20, 'cash_amount' => 200]));
        $item = $this->item('1005');
        $before = StockService::balance($item->id);

        $component = Livewire::test(CreatePosSalesReturn::class)
            ->set('data.original_document_id', $invoice->id);

        $lines = $component->get('data.lines');
        $this->assertCount(2, $lines);
        $key = collect($lines)->search(fn ($l) => (int) $l['item_id'] === $item->id);
        $this->assertEquals(3, (float) $lines[$key]['sold_qty']);

        $component->set('data.lines', [$key => array_merge($component->get("data.lines.{$key}"), ['quantity' => 1])])
            ->set('data.post_now', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $return = SalesDocument::query()->where('type', 'pos_return')->latest('id')->firstOrFail();
        $this->assertTrue($return->is_posted);
        $this->assertEquals(33.35, (float) $return->grand_total);
        $this->assertEquals(20.00, (float) $return->card_amount);
        $this->assertEquals(13.35, (float) $return->cash_amount);
        $this->assertEquals($before + 1, StockService::balance($item->id));

        $entry = $return->journalEntry;
        $this->assertEquals($entry->total_debit, $entry->total_credit);
        $this->assertSame(20.0, $this->accountTotals($entry, 'card')['credit']);
        $this->assertSame(13.35, $this->accountTotals($entry, 'cash')['credit']);

        $this->get(PosSalesReturnResource::getUrl('view', ['record' => $return]))->assertOk();
    }

    public function test_return_cannot_exceed_quantity_sold_including_earlier_returns(): void
    {
        $invoice = PosService::post($this->draftPos(['1005' => 2], ['cash_amount' => 100]));

        PosService::post($this->draftPos(['1005' => 1.5], ['original_document_id' => $invoice->id], 'pos_return'));
        $this->assertEquals(0.5, PosService::returnableQuantities($invoice)[PosService::lineKey($this->item('1005')->id, null)]['remaining']);

        $tooMany = $this->draftPos(['1005' => 1], ['original_document_id' => $invoice->id], 'pos_return');
        $this->expectException(ErpException::class);
        PosService::post($tooMany);
    }

    public function test_invoice_with_posted_returns_cannot_be_unposted(): void
    {
        $invoice = PosService::post($this->draftPos(['1005' => 2], ['cash_amount' => 100]));
        PosService::post($this->draftPos(['1005' => 1], ['original_document_id' => $invoice->id], 'pos_return'));

        $this->expectException(ErpException::class);
        PosService::unpost($invoice);
    }
}
