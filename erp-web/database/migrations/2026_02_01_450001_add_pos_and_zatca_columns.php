<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS, loyalty, incentives and ZATCA additions (additive only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->boolean('is_pickup_from_branch')->default(false);          // استلام من الفرع؟
            $table->decimal('points_discount', 18, 2)->default(0);             // value of the redeemed points
            $table->string('zatca_note_type')->nullable();                     // customer_discount | correction_credit | correction_debit
            $table->string('zatca_note_reason')->nullable();                   // ZATCA BR-KSA-17 instruction note
            $table->unsignedSmallInteger('zatca_attempts')->default(0);
        });

        Schema::table('drug_doses', function (Blueprint $table) {
            $table->string('frequency')->nullable();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
        });

        Schema::table('motivations', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = every selling user
        });
    }

    public function down(): void
    {
        Schema::table('motivations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('drug_doses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
            $table->dropColumn(['frequency', 'is_active']);
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn(['is_pickup_from_branch', 'points_discount', 'zatca_note_type', 'zatca_note_reason', 'zatca_attempts']);
        });
    }
};
