<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting module (الحسابات).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts: a tree. Only leaf accounts (is_postable) accept journal lines.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('number')->unique();          // رقم الحساب e.g. 10101001
            $table->unsignedTinyInteger('level')->default(1); // رقم المستوى
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('nature', 6)->default('debit'); // طبيعة الحساب: debit | credit
            $table->string('type')->default('general');   // general|customer|supplier|bank|cash|inventory|sales|purchases|vat|expense|revenue|equity|lc
            $table->string('balance_sheet_item')->nullable(); // بند الميزانية: assets|liabilities|equity|revenue|expenses
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('iban')->nullable();
            $table->string('account_no')->nullable();
            $table->string('swift_code')->nullable();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('address')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Letters of credit (الاعتمادات المستندية) – used by foreign purchases and journal lines.
        Schema::create('accreditations', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lc_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('inventory_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable()->index(); // FK added in purchases migration
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('value', 18, 2)->default(0);
            $table->decimal('bank_charges', 18, 2)->default(0);
            $table->date('open_date')->nullable();
            $table->date('close_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('accreditation_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accreditation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->nullable();
            $table->date('date');
            $table->string('fee_type')->nullable(); // bank charges, customs, shipping, insurance...
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();
        });

        // Journal entries (القيود / سندات القيد). Every posted document produces one entry.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date')->index();
            $table->string('entry_type')->default('manual'); // نوع القيد: manual|opening|sales|sales_return|pos|purchase|purchase_return|receipt|payment|notice|inventory|cheque_collection|closing
            $table->nullableMorphs('source');
            $table->text('description')->nullable();
            $table->decimal('total_debit', 18, 2)->default(0);
            $table->decimal('total_credit', 18, 2)->default(0);
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'fiscal_year', 'entry_type', 'number']);
        });

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('salesman_id')->nullable()->index();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accreditation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_no')->nullable();
            $table->date('document_date')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['account_id', 'journal_entry_id']);
        });

        // Cash box & cheque vouchers. One table, discriminated by `type`:
        // receipt_cash | receipt_cheque | payment_cash | payment_cheque | credit_notice | debit_notice
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date');
            $table->string('received_by')->nullable();   // مقبوض بواسطة / استلم بواسطة
            $table->string('pay_to')->nullable();        // ادفعوا لأمر / اصرفوا لأمر
            $table->string('manual_document_no')->nullable();
            $table->foreignId('account_id')->constrained(); // the cash/bank side: debit for receipts, credit for payments
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cheque_no')->nullable();
            $table->date('due_date')->nullable();
            $table->string('cheque_status')->nullable(); // under_collection|collected|returned
            $table->text('description')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['type', 'branch_id', 'fiscal_year', 'number']);
        });

        Schema::create('voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('description')->nullable();
            $table->unsignedBigInteger('salesman_id')->nullable()->index();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accreditation_id')->nullable()->constrained()->nullOnDelete();
            // cheque receipt lines: each line is a cheque
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cheque_no')->nullable();
            $table->date('due_date')->nullable();
            $table->string('cheque_status')->nullable(); // under_collection|collected|returned
            $table->unsignedBigInteger('cheque_collection_id')->nullable()->index();
            $table->timestamps();
        });

        // Cheque collection (تحصيل الشيكات): moves cheques from "under collection" to the bank.
        Schema::create('cheque_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->unsignedSmallInteger('fiscal_year')->index();
            $table->string('number');
            $table->date('date');
            $table->date('from_date');
            $table->date('to_date');
            $table->foreignId('bank_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'fiscal_year', 'number']);
        });

        // Murabaha financing contracts (عقود التمويل).
        Schema::create('financing_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
            $table->date('contract_date');
            $table->date('first_installment_date')->nullable();
            $table->decimal('murabaha_amount', 18, 2)->default(0);
            $table->decimal('bank_profit_margin', 18, 2)->default(0);
            $table->unsignedInteger('installments_count')->default(1);
            $table->decimal('installment_amount', 18, 2)->default(0);
            $table->foreignId('loan_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('interest_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('financing_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financing_contract_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->date('due_date');
            $table->decimal('amount', 18, 2);
            $table->boolean('is_paid')->default(false);
            $table->date('paid_at')->nullable();
            $table->timestamps();
        });

        // Document numbering sequences per (key, branch, year).
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->unsignedBigInteger('branch_id')->default(0);
            $table->unsignedSmallInteger('fiscal_year')->default(0);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['key', 'branch_id', 'fiscal_year']);
        });

        // Key/value system settings (default accounts etc.).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'document_sequences', 'financing_installments', 'financing_contracts', 'cheque_collections', 'voucher_lines', 'vouchers', 'journal_entry_lines', 'journal_entries', 'accreditation_fees', 'accreditations', 'banks', 'cost_centers', 'accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
