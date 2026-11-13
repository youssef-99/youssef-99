<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases module (المشتريات), foreign purchases and cash purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // type: purchase_order | purchase_invoice | purchase_return | foreign_purchase_invoice | cash_purchase_invoice | cash_purchase_return
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date')->index();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // payable / cash account
            $table->string('supplier_invoice_no')->nullable();
            $table->date('receipt_date')->nullable();
            $table->unsignedInteger('payment_period_days')->default(0);
            $table->date('due_date')->nullable();
            $table->string('reference_type')->nullable(); // returns: invoice | free
            $table->foreignId('original_document_id')->nullable()->constrained('purchase_documents')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_documents')->nullOnDelete();

            // foreign purchases
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->foreignId('accreditation_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('shipping_cost', 18, 2)->default(0);
            $table->decimal('purchase_expenses', 18, 2)->default(0);

            $table->text('notes')->nullable();
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('items_discount', 18, 2)->default(0);
            $table->decimal('additional_discount', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);

            $table->string('status')->default('draft'); // draft | approved | closed
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rasd_status')->nullable(); // Rasd drug track & trace: pending | prepared | sent | failed
            $table->timestamp('rasd_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'branch_id', 'fiscal_year', 'number']);
        });

        Schema::create('purchase_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->foreignId('item_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);  // سعر الصنف (excl. VAT)
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('discount2', 18, 2)->default(0);
            $table->decimal('discount2_percent', 8, 4)->default(0);
            $table->decimal('bonus_qty', 18, 3)->default(0);
            $table->decimal('bonus_percent', 8, 4)->default(0);
            $table->decimal('tax_percent', 8, 4)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('unit_net', 18, 4)->default(0);    // صافي الوحدة (cost per unit incl. bonus effect)
            $table->decimal('net', 18, 2)->default(0);         // incl. VAT
            $table->decimal('sale_price', 18, 4)->nullable();  // optionally update item/batch selling price
            $table->json('serial_numbers')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_lines');
        Schema::dropIfExists('purchase_documents');
    }
};
