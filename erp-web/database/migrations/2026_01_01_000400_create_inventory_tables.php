<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory module (المخزون).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('points_percent', 8, 4)->default(0); // نسبة النقاط للفئة
            $table->boolean('is_sales_category')->default(true); // فئة بيع؟
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('item_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('active_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('barcode')->nullable()->index();
            $table->string('gtin', 14)->nullable()->index();
            $table->string('reference_no')->nullable();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('category_id')->constrained('item_categories');
            $table->foreignId('group_id')->nullable()->constrained('item_groups')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manufacturer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_type')->default('standard'); // standard | batch_expiry | serial | service
            $table->string('item_nature')->default('stock');  // stock | service | consumable
            $table->decimal('vat_rate', 8, 4)->default(15);
            $table->string('tax_exemption_reason')->nullable(); // ZATCA exemption code e.g. VATEX-SA-HEA
            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('sale_price', 18, 4)->default(0);   // excl. VAT
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->boolean('price_includes_tax')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('sent_to_epos')->default(false);
            $table->timestamps();
        });

        Schema::create('active_ingredient_item', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('strength')->nullable();
            $table->primary(['item_id', 'active_ingredient_id']);
        });

        Schema::create('item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no');
            $table->date('expiry_date')->nullable();
            $table->string('description')->nullable();
            $table->decimal('last_purchase_price', 18, 4)->default(0);
            $table->decimal('sale_price', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'batch_no', 'expiry_date']);
        });

        Schema::create('item_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_no');
            $table->string('status')->default('in_stock'); // in_stock | sold | returned
            $table->nullableMorphs('source');
            $table->timestamps();
            $table->unique(['item_id', 'serial_no']);
        });

        Schema::create('item_balance_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('min_qty', 18, 3)->default(0);
            $table->decimal('max_qty', 18, 3)->default(0);
            $table->decimal('reorder_qty', 18, 3)->default(0);
            $table->timestamps();
            $table->unique(['item_id', 'branch_id', 'warehouse_id']);
        });

        // The stock ledger. Balances = SUM(qty_in - qty_out).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('item_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->date('date')->index();
            $table->string('movement_type'); // purchase|purchase_return|sale|sale_return|pos|pos_return|adjust_in|adjust_out|transfer_in|transfer_out|stocktake|opening
            $table->decimal('qty_in', 18, 3)->default(0);
            $table->decimal('qty_out', 18, 3)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->nullableMorphs('source');
            $table->timestamps();
            $table->index(['item_id', 'warehouse_id', 'item_batch_id']);
        });

        // Stock documents: one table, discriminated by `type`:
        // positive_adjustment | negative_adjustment | outbound_transfer | inbound_transfer | annual_stocktake | periodic_stocktake
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date');
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // offset account (adjustments/stocktake)
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('related_document_id')->nullable()->constrained('stock_documents')->nullOnDelete(); // inbound <- outbound
            $table->text('notes')->nullable();
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('items_discount', 18, 2)->default(0);
            $table->decimal('additional_discount', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->boolean('is_approved')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'branch_id', 'fiscal_year', 'number']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('item_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('system_qty', 18, 3)->default(0); // stocktake: book balance
            $table->decimal('quantity', 18, 3)->default(0);   // counted / moved quantity
            $table->decimal('price', 18, 4)->default(0);
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('net', 18, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_document_lines', 'stock_documents', 'stock_movements', 'item_balance_limits', 'item_serial_numbers', 'item_batches', 'active_ingredient_item', 'items', 'active_ingredients', 'manufacturers', 'agents', 'item_groups', 'item_categories', 'units'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
