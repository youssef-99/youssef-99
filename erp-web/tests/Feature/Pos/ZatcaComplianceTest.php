<?php

namespace Tests\Feature\Pos;

use App\Exceptions\ErpException;
use App\Filament\Resources\General\EgsUnitResource\Pages\ManageEgsUnits;
use App\Models\EgsUnit;
use App\Models\Item;
use App\Models\SalesDocument;
use App\Services\PosService;
use App\Services\SalesDocumentService;
use App\Services\Settings;
use App\Services\Zatca\UblInvoiceBuilder;
use App\Services\Zatca\ZatcaService;
use App\Services\Zatca\ZatcaXml;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\ErpTestCase;

/**
 * ZATCA review fixes: deferred submission, hash over the stored XML, XAdES, VAT categories,
 * consistent rounding, accepted statuses, unpost guard, encrypted secrets, refunds, billing reference.
 */
class ZatcaComplianceTest extends ErpTestCase
{
    use PosTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
    }

    protected function onboardedEgs(): EgsUnit
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privatePem);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'EGS1'], $key, ['digest_alg' => 'sha256']), null, $key, 365, ['digest_alg' => 'sha256'], 4242);
        openssl_x509_export($cert, $certPem);
        EgsUnit::query()->update(['is_active' => false]);

        return EgsUnit::query()->create([
            'branch_id' => $this->branchId(), 'unit_name' => 'EGS T', 'environment' => 'simulation',
            'private_key' => $privatePem, 'compliance_csid' => base64_encode(preg_replace('/-----[^-]+-----|\s+/', '', $certPem)), 'compliance_secret' => 's3cret',
        ]);
    }

    public function test_on_post_submission_waits_for_commit_and_is_skipped_on_rollback(): void
    {
        $this->onboardedEgs();
        Settings::set('zatca.submission_mode', 'on_post');
        Http::fake(['gw-fatoora.zatca.gov.sa/*' => Http::response(['reportingStatus' => 'REPORTED'], 200)]);

        $doc = $this->draftPos(['1005' => 1], ['cash_amount' => 50]);
        try {
            DB::transaction(function () use ($doc) {
                PosService::post($doc);
                Http::assertNothingSent();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        Http::assertNothingSent();
        $this->assertFalse($doc->refresh()->is_posted);
        $this->assertNull($doc->zatca_icv);
        $this->assertSame(0, (int) EgsUnit::query()->where('is_active', true)->value('last_icv'));

        $posted = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));
        Http::assertSentCount(1);
        $this->assertSame('reported', $posted->zatca_status);
        $this->assertSame(1, (int) $posted->zatca_icv);
    }

    public function test_hash_recomputed_from_stored_xml_and_xades_block(): void
    {
        $egs = $this->onboardedEgs();
        $doc = PosService::post($this->draftPos(['1005' => 2, '1008' => 1], ['cash_amount' => 200, 'additional_discount' => 5]));

        $this->assertSame($doc->zatca_hash, ZatcaXml::invoiceHash($doc->zatca_xml));

        $dom = ZatcaXml::load($doc->zatca_xml);
        $xpath = ZatcaXml::xpath($dom);
        $sig = '/inv:Invoice/ext:UBLExtensions//ds:Signature';
        $this->assertSame($doc->zatca_hash, $xpath->evaluate("string({$sig}/ds:SignedInfo/ds:Reference[@Id='invoiceSignedData']/ds:DigestValue)"));
        $properties = $xpath->query("{$sig}/ds:Object//xades:SignedProperties")->item(0);
        $this->assertSame(base64_encode(hash('sha256', $properties->C14N())), $xpath->evaluate("string({$sig}/ds:SignedInfo/ds:Reference[@URI='#xadesSignedProperties']/ds:DigestValue)"));
        $this->assertSame('4242', $xpath->evaluate("string({$sig}//ds:X509SerialNumber)"));
        $this->assertStringContainsString('CN=EGS1', $xpath->evaluate("string({$sig}//ds:X509IssuerName)"));
        $this->assertSame(1, $xpath->query('/inv:Invoice/cac:Signature')->length);

        $signatureValue = $xpath->evaluate("string({$sig}/ds:SignatureValue)");
        $tags = ZatcaService::decodeQr($xpath->evaluate("string(/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject)"));
        $this->assertSame($doc->zatca_qr, $xpath->evaluate("string(/inv:Invoice/cac:AdditionalDocumentReference[cbc:ID='QR']//cbc:EmbeddedDocumentBinaryObject)"));
        $this->assertSame($signatureValue, $tags[7]);
        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private($egs->private_key))['key'];
        $this->assertSame(1, openssl_verify(base64_decode($doc->zatca_hash), base64_decode($signatureValue), $publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_tax_subtotals_split_by_category_with_exemption_codes(): void
    {
        $zero = $this->item('1008'); // 0% VAT, VATEX-SA-HEA
        $exempt = Item::query()->create(array_merge($zero->only(['category_id', 'unit_id']), [
            'code' => '9001', 'name_ar' => 'خدمة معفاة', 'vat_rate' => 0, 'tax_exemption_reason' => 'VATEX-SA-29', 'sale_price' => 10, 'item_type' => 'standard', 'item_nature' => 'service',
        ]));
        $doc = $this->draftPos(['1005' => 1, '1008' => 1, '9001' => 1], ['cash_amount' => 500, 'additional_discount' => 6]);
        $doc = PosService::post($doc);

        $xpath = ZatcaXml::xpath(ZatcaXml::load($doc->zatca_xml));
        $subtotals = $xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal');
        $this->assertSame(3, $subtotals->length);
        $byCategory = [];
        foreach ($subtotals as $node) {
            $byCategory[$xpath->evaluate('string(cac:TaxCategory/cbc:ID)', $node)] = $xpath->evaluate('string(cac:TaxCategory/cbc:TaxExemptionReasonCode)', $node);
        }
        $this->assertSame(['S' => '', 'Z' => 'VATEX-SA-HEA', 'E' => 'VATEX-SA-29'], $byCategory);
        $this->assertSame('Private healthcare to citizen', $xpath->evaluate("string(//cac:TaxSubtotal[cac:TaxCategory/cbc:ID='Z']/cac:TaxCategory/cbc:TaxExemptionReason)"));

        // The document allowance is split per category and adds up.
        $allowances = $xpath->query('/inv:Invoice/cac:AllowanceCharge');
        $this->assertSame(3, $allowances->length);
        $this->assertEquals(6.0, round(array_sum(array_map(fn ($n) => (float) $xpath->evaluate('string(cbc:Amount)', $n), iterator_to_array($allowances))), 2));
    }

    public function test_line_subtotal_and_header_amounts_are_consistent(): void
    {
        $doc = $this->draftPos(['1001' => 3, '1002' => 7, '1005' => 1, '1006' => 13], ['cash_amount' => 1000, 'additional_discount_percent' => 7]);
        $doc->lines()->first()->update(['discount_percent' => 12.5]);
        $doc = PosService::post($doc);
        $xpath = ZatcaXml::xpath(ZatcaXml::load($doc->zatca_xml));
        $num = fn (string $q, $ctx = null) => (float) $xpath->evaluate("string({$q})", $ctx);

        $lineSum = 0.0;
        foreach ($xpath->query('/inv:Invoice/cac:InvoiceLine') as $line) {
            $net = $num('cbc:LineExtensionAmount', $line);
            $lineSum += $net;
            // BR-KSA-EN16931-11: net price × quantity = line net amount
            $this->assertEqualsWithDelta($net, round($num('cac:Price/cbc:PriceAmount', $line) * $num('cbc:InvoicedQuantity', $line), 2), 0.001);
            $base = $xpath->evaluate('string(cac:Price/cac:AllowanceCharge/cbc:BaseAmount)', $line);
            if ($base !== '') {
                $this->assertEqualsWithDelta((float) $base - $num('cac:Price/cac:AllowanceCharge/cbc:Amount', $line), $num('cac:Price/cbc:PriceAmount', $line), 0.000001);
            }
        }

        $taxSum = 0.0;
        $taxableSum = 0.0;
        foreach ($xpath->query('/inv:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $sub) {
            $taxSum += $num('cbc:TaxAmount', $sub);
            $taxableSum += $num('cbc:TaxableAmount', $sub);
            $this->assertEqualsWithDelta(round($num('cbc:TaxableAmount', $sub) * $num('cac:TaxCategory/cbc:Percent', $sub) / 100, 2), $num('cbc:TaxAmount', $sub), 0.001);
        }

        $header = '/inv:Invoice/cac:LegalMonetaryTotal/';
        $this->assertEqualsWithDelta(round($lineSum, 2), $num($header.'cbc:LineExtensionAmount'), 0.001);
        $this->assertEqualsWithDelta(round($taxSum, 2), $num('/inv:Invoice/cac:TaxTotal[1]/cbc:TaxAmount'), 0.001); // BR-CO-14
        $this->assertEqualsWithDelta(round($taxableSum, 2), $num($header.'cbc:TaxExclusiveAmount'), 0.001);
        $this->assertEqualsWithDelta($num($header.'cbc:TaxExclusiveAmount') + $num('/inv:Invoice/cac:TaxTotal[1]/cbc:TaxAmount'), $num($header.'cbc:TaxInclusiveAmount'), 0.001);
        $this->assertEqualsWithDelta($num($header.'cbc:LineExtensionAmount') - $num($header.'cbc:AllowanceTotalAmount'), $num($header.'cbc:TaxExclusiveAmount'), 0.001);

        $tags = ZatcaService::decodeQr($doc->zatca_qr);
        $this->assertSame(number_format($num($header.'cbc:TaxInclusiveAmount'), 2, '.', ''), $tags[4]);
        $this->assertSame(UblInvoiceBuilder::breakdown($doc->load('lines.item'))['tax_total'], (float) $tags[5]);
    }

    public function test_warning_status_counts_as_accepted(): void
    {
        $doc = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));
        $doc->forceFill(['zatca_status' => 'warning'])->saveQuietly();

        $this->assertFalse(app(ZatcaService::class)->canSubmit($doc));
        app(ZatcaService::class)->submit($doc);
        Http::assertNothingSent();

        $this->expectException(ErpException::class);
        $this->expectExceptionMessage(__('This invoice was reported to ZATCA. Issue a return or credit note instead.'));
        PosService::unpost($doc);
    }

    public function test_document_with_an_invoice_counter_cannot_be_unposted(): void
    {
        $doc = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));
        $this->assertNotNull($doc->zatca_icv);

        try {
            PosService::unpost($doc);
            $this->fail('A document in the ZATCA chain was unposted.');
        } catch (ErpException $e) {
            $this->assertStringContainsString('ICV', $e->getMessage());
        }

        $this->assertTrue($doc->refresh()->is_posted);
    }

    public function test_egs_secrets_are_encrypted_hidden_and_kept_when_blank(): void
    {
        $egs = $this->onboardedEgs();
        $raw = DB::table('egs_units')->where('id', $egs->id)->first();

        $this->assertStringNotContainsString('BEGIN', (string) $raw->private_key);
        $this->assertNotSame('s3cret', $raw->compliance_secret);
        $this->assertSame('s3cret', $egs->refresh()->compliance_secret);
        $this->assertArrayNotHasKey('private_key', $egs->toArray());
        $this->assertArrayNotHasKey('compliance_secret', $egs->toArray());

        Livewire::test(ManageEgsUnits::class)
            ->mountTableAction('edit', $egs)
            ->assertTableActionDataSet(['private_key' => null, 'compliance_secret' => null])
            ->setTableActionData(['unit_name' => 'EGS renamed'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $egs->refresh();
        $this->assertSame('EGS renamed', $egs->unit_name);
        $this->assertSame('s3cret', $egs->compliance_secret);
        $this->assertStringContainsString('BEGIN', $egs->private_key);

        // The data migration is idempotent and encrypts plain values.
        DB::table('egs_units')->where('id', $egs->id)->update(['production_secret' => 'plain']);
        $migration = require database_path('migrations/2026_02_01_450002_encrypt_egs_unit_secrets.php');
        $migration->up();
        $migration->up();
        $this->assertSame('plain', $egs->refresh()->production_secret);
        $this->assertSame('s3cret', $egs->compliance_secret);
    }

    public function test_card_refund_excludes_card_already_refunded(): void
    {
        $invoice = PosService::post($this->draftPos(['1005' => 3], ['card_amount' => 70, 'cash_amount' => 50]));

        $first = PosService::post($this->draftPos(['1005' => 2], ['original_document_id' => $invoice->id], 'pos_return'));
        $this->assertEquals(66.70, (float) $first->card_amount);

        $second = PosService::post($this->draftPos(['1005' => 1], ['original_document_id' => $invoice->id], 'pos_return'));
        $this->assertEquals(3.30, (float) $second->card_amount);
        $this->assertEquals(30.05, (float) $second->cash_amount);
    }

    public function test_zatca_return_requires_the_original_invoice(): void
    {
        $return = $this->draftPos(['1005' => 1], [], 'pos_return');
        $return->forceFill(['original_document_id' => null])->saveQuietly();

        try {
            SalesDocumentService::post($return);
            $this->fail('A return without its original invoice was posted.');
        } catch (ErpException $e) {
            $this->assertStringContainsString(__('original invoice'), $e->getMessage());
        }

        $this->assertFalse($return->refresh()->is_posted);
    }

    public function test_submit_pending_command_sends_backlog_in_icv_order(): void
    {
        $this->onboardedEgs();
        Settings::set('zatca.submission_mode', 'manual');
        $first = PosService::post($this->draftPos(['1005' => 1], ['cash_amount' => 50]));
        $second = PosService::post($this->draftPos(['1006' => 1], ['cash_amount' => 50]));
        SalesDocument::query()->whereKey([$first->id, $second->id])->update(['posted_at' => now()->subHour()]);
        $order = [];
        Http::fake(function ($request) use (&$order) {
            $order[] = $request['uuid'];

            return Http::response(['reportingStatus' => 'REPORTED'], 200);
        });

        $this->artisan('zatca:submit-pending')->assertSuccessful();

        $this->assertSame([$first->uuid, $second->uuid], $order);
        $this->assertSame('reported', $second->refresh()->zatca_status);
    }
}
