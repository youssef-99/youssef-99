<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Pos\Shared\ZatcaDocumentTable;
use App\Filament\Resources\Pos\ZatcaCreditNoteResource;
use App\Filament\Resources\Pos\ZatcaCreditNoteResource\Pages\ListZatcaCreditNotes;
use App\Filament\Resources\Pos\ZatcaSalesInvoiceResource;
use App\Filament\Resources\Pos\ZatcaSalesInvoiceResource\Pages\ListZatcaSalesInvoices;
use App\Filament\Resources\Pos\ZatcaSalesReturnResource;
use App\Models\Company;
use App\Models\EgsUnit;
use App\Models\SalesDocument;
use App\Services\PosService;
use App\Services\Settings;
use App\Services\Zatca\ZatcaNoteService;
use App\Services\Zatca\ZatcaService;
use App\Services\Zatca\ZatcaXml;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\ErpTestCase;

class ZatcaTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        Settings::set('zatca.submission_mode', 'manual');
    }

    /** An onboarded EGS unit with an EC key and a self-signed CSID certificate. */
    protected function egs(): EgsUnit
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privatePem);
        $csr = openssl_csr_new(['commonName' => 'EGS1'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        $certBody = preg_replace('/-----[^-]+-----|\s+/', '', $certPem);

        EgsUnit::query()->update(['is_active' => false]);

        return EgsUnit::query()->create([
            'branch_id' => $this->branchId(),
            'unit_name' => 'EGS 1',
            'environment' => 'simulation',
            'private_key' => $privatePem,
            'compliance_csid' => base64_encode($certBody),
            'compliance_secret' => 'secret',
        ]);
    }

    public function test_zatca_pages_render(): void
    {
        $this->get(ZatcaSalesInvoiceResource::getUrl('index'))->assertOk()->assertSee(__('Submission Status'));
        $this->get(ZatcaSalesReturnResource::getUrl('index'))->assertOk();
        $this->get(ZatcaCreditNoteResource::getUrl('index'))->assertOk();
    }

    public function test_qr_tlv_decodes_and_hash_chain_links(): void
    {
        $egs = $this->egs();
        $first = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));
        $second = PosService::post($this->draftPos(['1006' => 2], ['cash_amount' => 50]));

        $this->assertSame('simplified', $first->zatca_invoice_type);
        $this->assertSame(ZatcaService::INITIAL_PIH, $first->zatca_previous_hash);
        $this->assertSame($first->zatca_icv + 1, $second->zatca_icv);
        $this->assertSame($first->zatca_hash, $second->zatca_previous_hash);
        $this->assertSame($second->zatca_icv, (int) $egs->refresh()->last_icv);
        $this->assertSame($second->zatca_hash, $egs->last_invoice_hash);

        $tags = ZatcaService::decodeQr($second->zatca_qr);
        $company = Company::query()->first();
        $this->assertSame((string) $company->name_ar, $tags[1]);
        $this->assertSame((string) $company->vat_no, $tags[2]);
        $this->assertSame($second->date->format('Y-m-d').'T12:00:00', $tags[3]);
        $this->assertSame(number_format((float) $second->grand_total, 2, '.', ''), $tags[4]);
        $this->assertSame(number_format((float) $second->tax_total, 2, '.', ''), $tags[5]);
        $this->assertSame($second->zatca_hash, $tags[6]);
        $this->assertArrayHasKey(7, $tags);
        $this->assertArrayHasKey(9, $tags);

        $publicKey = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($tags[8]), 64, "\n")."-----END PUBLIC KEY-----\n");
        $this->assertSame($second->zatca_hash, ZatcaXml::invoiceHash($second->zatca_xml));
        $this->assertSame(1, openssl_verify(base64_decode($second->zatca_hash), base64_decode($tags[7]), $publicKey, OPENSSL_ALGO_SHA256));

        // Regenerating keeps the place in the chain.
        app(ZatcaService::class)->prepare($first->refresh());
        $this->assertSame($second->zatca_icv, (int) $egs->refresh()->last_icv);
        $this->assertStringContainsString('<cbc:ID>QR</cbc:ID>', $first->zatca_xml);
    }

    public function test_submit_reports_simplified_invoice(): void
    {
        $this->egs();
        Http::fake(['gw-fatoora.zatca.gov.sa/*' => Http::response(['reportingStatus' => 'REPORTED', 'validationResults' => ['errorMessages' => [], 'warningMessages' => []]], 200)]);
        $doc = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));

        app(ZatcaService::class)->submit($doc);

        $this->assertSame('reported', $doc->refresh()->zatca_status);
        $this->assertSame(1, (int) $doc->zatca_attempts);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/simulation/invoices/reporting/single')
            && $request['uuid'] === $doc->uuid && $request['invoiceHash'] === $doc->zatca_hash);
    }

    public function test_rejected_invoice_can_be_resubmitted_from_the_page(): void
    {
        $this->egs();
        Http::fakeSequence('gw-fatoora.zatca.gov.sa/*')
            ->push(['reportingStatus' => 'NOT_REPORTED', 'validationResults' => ['errorMessages' => [['code' => 'BR-KSA-37', 'message' => 'Seller address']]]], 400)
            ->push(['reportingStatus' => 'REPORTED'], 200);
        $doc = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));

        app(ZatcaService::class)->submit($doc);
        $this->assertSame('rejected', $doc->refresh()->zatca_status);
        $this->assertStringContainsString('BR-KSA-37', $doc->zatca_error);

        Livewire::test(ListZatcaSalesInvoices::class)
            ->callTableAction('zatcaSubmit', $doc);

        $this->assertSame('reported', $doc->refresh()->zatca_status);
        $this->assertNull($doc->zatca_error);
        $this->assertSame(2, (int) $doc->zatca_attempts);
    }

    public function test_standard_invoice_is_cleared_and_on_post_mode_submits_automatically(): void
    {
        $this->egs();
        Settings::set('zatca.submission_mode', 'on_post');
        Http::fake(['gw-fatoora.zatca.gov.sa/*' => Http::response(['clearanceStatus' => 'CLEARED', 'clearedInvoice' => base64_encode('<Invoice>cleared</Invoice>')], 200)]);

        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->firstOrFail();
        $note = ZatcaNoteService::createCustomerDiscount($invoice, 10, 'Volume discount');

        $this->assertSame('standard', $note->zatca_invoice_type);
        $this->assertSame('cleared', $note->zatca_status);
        $this->assertSame('<Invoice>cleared</Invoice>', $note->zatca_xml);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/invoices/clearance/single'));
    }

    public function test_submit_without_egs_records_an_error_and_offline_mode_never_calls_the_network(): void
    {
        $doc = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));

        app(ZatcaService::class)->submit($doc);
        $this->assertSame('error', $doc->refresh()->zatca_status);

        config(['erp.zatca.offline' => true]);
        ZatcaDocumentTable::send($doc);
        $this->assertSame('reported', $doc->refresh()->zatca_status);
        Http::assertNothingSent();
    }

    public function test_customer_discount_credit_note_posts_and_references_the_invoice(): void
    {
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->firstOrFail();

        Livewire::test(ListZatcaCreditNotes::class)
            ->callAction('addCustomerDiscount', ['invoice_id' => $invoice->id, 'amount' => 50, 'date' => now()->toDateString(), 'reason' => 'Volume discount'])
            ->assertHasNoActionErrors();

        $note = SalesDocument::query()->where('type', ZatcaNoteService::CREDIT_NOTE)->latest('id')->firstOrFail();
        $this->assertTrue($note->is_posted);
        $this->assertSame('customer_discount', $note->zatca_note_type);
        $this->assertEquals(50.00, (float) $note->net_total);
        $this->assertEquals(round((float) $invoice->tax_total * 50 / (float) $invoice->net_total, 1), round((float) $note->tax_total, 1));
        $this->assertEquals($note->journalEntry->total_debit, $note->journalEntry->total_credit);
        $this->assertSame((float) $note->grand_total, (float) $note->journalEntry->lines()->where('account_id', $invoice->account_id)->sum('credit'));
        $this->assertStringContainsString('>381<', $note->zatca_xml);
        $this->assertStringContainsString('<cbc:InstructionNote>Volume discount</cbc:InstructionNote>', $note->zatca_xml);
        $this->assertStringContainsString('<cbc:ID>'.$invoice->number.'</cbc:ID>', $note->zatca_xml);
    }

    public function test_invoice_correction_debit_note(): void
    {
        $invoice = SalesDocument::query()->where('type', 'sales_invoice')->with('lines')->firstOrFail();
        $line = $invoice->lines->first();

        $note = ZatcaNoteService::createCorrection($invoice, 'debit', 'Price correction', [
            ['item_id' => $line->item_id, 'quantity' => 2, 'unit_price' => 1.5],
        ]);

        $this->assertSame(ZatcaNoteService::DEBIT_NOTE, $note->type);
        $this->assertEquals(3.00, (float) $note->net_total);
        $this->assertStringContainsString('>383<', $note->zatca_xml);
        $this->assertSame((float) $note->grand_total, (float) $note->journalEntry->lines()->where('account_id', $invoice->account_id)->sum('debit'));
    }
}
