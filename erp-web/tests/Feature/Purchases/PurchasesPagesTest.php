<?php

namespace Tests\Feature\Purchases;

use Filament\Facades\Filament;
use Tests\ErpTestCase;

/**
 * Every Purchases and Cash Transactions page renders (index, create, view, edit) with demo data.
 */
class PurchasesPagesTest extends ErpTestCase
{
    public function test_purchases_and_cash_pages_render(): void
    {
        $this->seedDemo();
        $failures = [];

        $resources = array_filter(
            Filament::getPanel('admin')->getResources(),
            fn (string $resource) => str_starts_with($resource, 'App\\Filament\\Resources\\Purchases\\') || str_starts_with($resource, 'App\\Filament\\Resources\\Cash\\'),
        );
        $this->assertGreaterThanOrEqual(18, count($resources));

        foreach ($resources as $resource) {
            foreach (['index', 'create'] as $page) {
                if ($resource::hasPage($page)) {
                    $response = $this->get($resource::getUrl($page));
                    if ($response->status() !== 200) {
                        $failures[] = $response->status().' '.$resource::getUrl($page).' '.($response->exception?->getMessage() ?? '');
                    }
                }
            }

            if (! $resource::hasPage('view') && ! $resource::hasPage('edit')) {
                continue;
            }

            $record = $resource::getEloquentQuery()->first();
            if (! $record) {
                $failures[] = "no demo record for {$resource}";

                continue;
            }

            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page) && ($page !== 'edit' || $resource::canEdit($record))) {
                    $response = $this->get($resource::getUrl($page, ['record' => $record]));
                    if ($response->status() !== 200) {
                        $failures[] = $response->status().' '.$resource::getUrl($page, ['record' => $record]).' '.($response->exception?->getMessage() ?? '');
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
