<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parties: customers, suppliers, salesmen, reps, doctors, loyalty customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->string('supplier_type')->default('local'); // local | foreign
            $table->foreignId('supplier_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vat_no', 20)->nullable();
            $table->string('commercial_register')->nullable();
            $table->date('register_expiry_date')->nullable();
            $table->string('address')->nullable();
            $table->string('street_name')->nullable();
            $table->string('building_no')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('additional_no')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->decimal('opening_debit', 18, 2)->default(0);
            $table->decimal('opening_credit', 18, 2)->default(0);
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->unsignedInteger('payment_term_days')->default(0);
            $table->string('gln')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Cash-module suppliers: no account or opening balance; VAT number is required.
        Schema::create('cash_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('vat_no', 20);
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('street_name')->nullable();
            $table->string('building_no')->nullable();
            $table->string('additional_no')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile')->nullable();
            $table->decimal('commission_percent', 8, 4)->default(0);
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('marketing_reps', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mobile')->nullable();
            $table->decimal('commission_percent', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sales_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->string('price_basis')->default('sale_price'); // sale_price | cost_plus
            $table->decimal('markup_percent', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->boolean('is_taxable')->default(true);         // عميل ضريبي؟
            $table->boolean('discount_eligible')->default(false); // يحسب له خصومات؟
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_type')->default('company'); // individual | company | government
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('marketing_rep_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->string('vat_no', 20)->nullable();
            $table->string('commercial_register')->nullable();
            $table->date('register_expiry_date')->nullable();
            $table->string('address')->nullable();
            $table->string('street_name')->nullable();
            $table->string('building_no')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('additional_no')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sales_policy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('account_opening_date')->nullable();
            $table->date('last_reconciliation_date')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            $table->decimal('opening_debit', 18, 2)->default(0);
            $table->decimal('opening_credit', 18, 2)->default(0);
            $table->decimal('credit_limit', 18, 2)->default(0);
            $table->unsignedInteger('payment_term_days')->default(0);
            $table->string('gln')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('vat_no', 20)->nullable();
            $table->string('commercial_register')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->string('street_name')->nullable();
            $table->string('building_no')->nullable();
            $table->string('additional_no')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->timestamps();
        });

        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('specialty')->nullable();
            $table->string('clinic')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Loyalty points customers (عملاء النقاط).
        Schema::create('points_customers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('mobile')->unique();
            $table->string('delivery_code')->nullable(); // دليفري كود
            $table->string('email')->nullable();
            $table->decimal('points_balance', 18, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('accreditations', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accreditations', fn (Blueprint $t) => $t->dropForeign(['supplier_id']));
        foreach (['points_customers', 'doctors', 'cash_customers', 'customers', 'sales_policies', 'marketing_reps', 'salesmen', 'customer_groups', 'cash_suppliers', 'suppliers', 'supplier_groups'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
