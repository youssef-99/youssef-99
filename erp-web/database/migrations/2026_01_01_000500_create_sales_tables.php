<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales module (المبيعات), POS and cash sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        // All sales documents share one table, discriminated by `type`:
        // quotation | customer_order | sales_invoice | sales_return | pos_invoice | pos_return | cash_sales_invoice | cash_sales_return
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date')->index();
            $table->time('time')->nullable();
            $table->date('due_date')->nullable();
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('points_customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // receivable / cash account
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('original_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->string('manual_no')->nullable();       // رقم السند اليدوى / رقم الفاتورة اليدوى
            $table->string('payment_method')->default('credit'); // cash | card | credit | mixed
            $table->text('notes')->nullable();

            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('items_discount', 18, 2)->default(0);
            $table->decimal('additional_discount', 18, 2)->default(0);
            $table->decimal('additional_discount_percent', 8, 4)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->decimal('cost_total', 18, 2)->default(0);

            // POS payment
            $table->decimal('cash_amount', 18, 2)->default(0);
            $table->decimal('card_amount', 18, 2)->default(0);
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->decimal('change_due', 18, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->boolean('is_external')->default(false);               // فاتورة خارجية؟
            $table->foreignId('received_from_branch_id')->nullable()->constrained('branches')->nullOnDelete(); // استلم من الفرع
            $table->boolean('needs_issue_transfer')->default(false);      // يحتاج تحويل منصرف؟

            // Loyalty
            $table->decimal('points_earned', 18, 2)->default(0);
            $table->decimal('points_redeemed', 18, 2)->default(0);

            // Workflow: draft | confirmed | approved | partially_approved | rejected
            $table->string('status')->default('draft');
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();

            // ZATCA
            $table->string('zatca_invoice_type')->nullable(); // standard (B2B) | simplified (B2C)
            $table->unsignedBigInteger('zatca_icv')->nullable();
            $table->string('zatca_hash')->nullable();
            $table->string('zatca_previous_hash')->nullable();
            $table->text('zatca_qr')->nullable();
            $table->longText('zatca_xml')->nullable();
            $table->string('zatca_status')->nullable(); // pending | reported | cleared | warning | rejected | error
            $table->text('zatca_error')->nullable();
            $table->timestamp('zatca_submitted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'branch_id', 'fiscal_year', 'number']);
        });

        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drug_dose_id')->nullable()->index();
            $table->foreignId('promotion_id')->nullable()->index();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->decimal('approved_qty', 18, 3)->nullable();  // customer orders
            $table->decimal('free_qty', 18, 3)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);   // excl. VAT
            $table->decimal('gross', 18, 2)->default(0);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->decimal('tax_percent', 8, 4)->default(0);
            $table->decimal('tax', 18, 2)->default(0);
            $table->decimal('net', 18, 2)->default(0);           // incl. VAT
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->json('serial_numbers')->nullable();
            $table->timestamps();
        });

        Schema::create('drug_doses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        // Promotions (العروض): percent | fixed_price | buy_x_get_y
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->string('promotion_type')->default('percent');
            $table->decimal('value', 18, 4)->default(0);
            $table->decimal('buy_qty', 18, 3)->default(0);
            $table->decimal('free_qty', 18, 3)->default(0);
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Staff incentives (الحوافز): per unit sold or percent of sales value.
        Schema::create('motivations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $table->string('motivation_type')->default('per_unit'); // per_unit | percent
            $table->decimal('value', 18, 4)->default(0);
            $table->date('from_date');
            $table->date('to_date');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('points_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('points_customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->decimal('points', 18, 2); // + earned, - redeemed
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('to_salesman_id')->constrained('salesmen');
            $table->json('customer_ids');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['customer_transfers', 'points_transactions', 'motivations', 'promotions', 'drug_doses', 'sales_document_lines', 'sales_documents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
