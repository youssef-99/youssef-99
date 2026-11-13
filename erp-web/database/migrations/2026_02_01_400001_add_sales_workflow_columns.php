<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales workflow: quotation / customer order conversion link, order approval and rejection,
 * and the original file names of customer documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('source_document_id')->nullable()->after('original_document_id')->constrained('sales_documents')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->json('attachment_names')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('attachment_names');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['rejection_reason', 'approved_at']);
        });
    }
};
