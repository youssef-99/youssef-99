<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases: Rasd (SFDA drug track & trace) file + response on purchase documents,
 * open / closed status on letters of credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_documents', 'rasd_file')) {
                $table->longText('rasd_file')->nullable();
            }
            if (! Schema::hasColumn('purchase_documents', 'rasd_reference')) {
                $table->string('rasd_reference')->nullable();
            }
            if (! Schema::hasColumn('purchase_documents', 'rasd_response')) {
                $table->text('rasd_response')->nullable();
            }
        });

        Schema::table('accreditations', function (Blueprint $table) {
            if (! Schema::hasColumn('accreditations', 'status')) {
                $table->string('status')->default('open'); // open | closed
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_documents', function (Blueprint $table) {
            $table->dropColumn(['rasd_file', 'rasd_reference', 'rasd_response']);
        });

        Schema::table('accreditations', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
