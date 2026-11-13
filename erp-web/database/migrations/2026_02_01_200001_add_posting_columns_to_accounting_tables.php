<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting: cheque selection on collections, posting columns on murabaha contracts and installments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheque_collections', function (Blueprint $table) {
            $table->json('cheque_line_ids')->nullable();
        });

        Schema::table('financing_contracts', function (Blueprint $table) {
            $table->foreignId('funding_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_posted')->default(false);
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
        });

        Schema::table('financing_installments', function (Blueprint $table) {
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('financing_installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_account_id');
            $table->dropColumn('journal_entry_id');
        });

        Schema::table('financing_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funding_account_id');
            $table->dropColumn(['is_posted', 'posted_at', 'journal_entry_id']);
        });

        Schema::table('cheque_collections', function (Blueprint $table) {
            $table->dropColumn('cheque_line_ids');
        });
    }
};
