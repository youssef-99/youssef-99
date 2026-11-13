<?php

namespace Tests\Feature\Sales;

use App\Filament\Resources\Sales\CustomerOrderResource;
use App\Filament\Resources\Sales\CustomerResource;
use App\Filament\Resources\Sales\QuotationResource;
use App\Filament\Resources\Sales\SalesReturnResource;
use App\Models\Customer;
use App\Models\SalesDocument;
use Tests\ErpTestCase;

class SalesPagesTest extends ErpTestCase
{
    public function test_view_pages_render_with_demo_records(): void
    {
        $this->seedDemo();

        $this->get(CustomerResource::getUrl('view', ['record' => Customer::query()->first()]))->assertOk()->assertSee(__('Last Reconciliation Date'));
        $this->get(QuotationResource::getUrl('view', ['record' => SalesDocument::query()->where('type', 'quotation')->first()]))->assertOk();
        $this->get(CustomerOrderResource::getUrl('view', ['record' => SalesDocument::query()->where('type', 'customer_order')->first()]))->assertOk();
        $this->get(SalesReturnResource::getUrl('view', ['record' => SalesDocument::query()->where('type', 'sales_return')->first()]))->assertOk();
    }
}
