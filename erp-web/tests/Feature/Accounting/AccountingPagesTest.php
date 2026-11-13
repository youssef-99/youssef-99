<?php

namespace Tests\Feature\Accounting;

use App\Filament\Resources\Accounting\AccountResource;
use App\Filament\Resources\Accounting\BankResource;
use App\Filament\Resources\Accounting\ChequeCollectionResource;
use App\Filament\Resources\Accounting\ChequeReceiptVoucherResource;
use App\Filament\Resources\Accounting\CostCenterResource;
use App\Filament\Resources\Accounting\CreditNoticeResource;
use App\Filament\Resources\Accounting\DebitNoticeResource;
use App\Filament\Resources\Accounting\FinancingContractResource;
use App\Filament\Resources\Accounting\JournalVoucherResource;
use App\Filament\Resources\Accounting\PaymentCashVoucherResource;
use App\Filament\Resources\Accounting\PaymentChequeVoucherResource;
use App\Filament\Resources\Accounting\ReceiptVoucherResource;
use App\Models\JournalEntry;
use Tests\ErpTestCase;

/**
 * Every accounting page renders with the demo data (list, add, view and edit where a record exists).
 */
class AccountingPagesTest extends ErpTestCase
{
    public function test_accounting_pages_render(): void
    {
        $this->seedDemo();
        $failures = [];

        $resources = [
            AccountResource::class, BankResource::class, CostCenterResource::class, JournalVoucherResource::class,
            ReceiptVoucherResource::class, PaymentCashVoucherResource::class, PaymentChequeVoucherResource::class,
            CreditNoticeResource::class, DebitNoticeResource::class, ChequeReceiptVoucherResource::class,
            ChequeCollectionResource::class, FinancingContractResource::class,
        ];

        foreach ($resources as $resource) {
            $urls = [$resource::getUrl('index')];
            if ($resource::hasPage('create')) {
                $urls[] = $resource::getUrl('create');
            }
            $record = $resource::getEloquentQuery()->latest('id')->first();
            $this->assertNotNull($record, "No demo record for {$resource}");
            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page) && ($page === 'view' || $resource::canEdit($record))) {
                    $urls[] = $resource::getUrl($page, ['record' => $record]);
                }
            }
            foreach ($urls as $url) {
                $status = $this->get($url)->status();
                if ($status !== 200) {
                    $failures[] = "{$status} {$url}";
                }
            }
        }

        $manual = JournalEntry::query()->where('entry_type', 'manual')->firstOrFail();
        $manual->forceFill(['is_posted' => false])->saveQuietly();
        $this->get(JournalVoucherResource::getUrl('edit', ['record' => $manual]))->assertOk();

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
