<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General module: settings and master data (عام).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('commercial_register')->nullable();
            $table->string('membership_no')->nullable();
            $table->string('vat_no', 20)->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('street_name')->nullable();
            $table->string('building_no')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('additional_no')->nullable();
            $table->string('city_name')->nullable();
            $table->string('district_name')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // User groups = roles. `permissions` holds a list of permission keys, e.g. "sales.customers.view".
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('iso_code', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('iso_code', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // Business activities (نشاط العميل).
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });

        // Stakeholders / partners (أصحاب المصلحة).
        Schema::create('stakeholders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('share_percent', 8, 4)->default(0);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        // ZATCA EGS (e-invoice generation solution) units.
        Schema::create('egs_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('unit_name');
            $table->string('serial_number')->nullable();
            $table->string('invoicing_type')->default('1100'); // ZATCA: 1000 standard, 0100 simplified, 1100 both
            $table->string('industry_sector')->nullable();
            $table->string('location')->nullable();
            $table->string('environment')->default('sandbox'); // sandbox | simulation | production
            $table->text('private_key')->nullable();
            $table->text('csr')->nullable();
            $table->text('compliance_csid')->nullable();
            $table->text('compliance_secret')->nullable();
            $table->text('production_csid')->nullable();
            $table->text('production_secret')->nullable();
            $table->unsignedBigInteger('last_icv')->default(0);
            $table->string('last_invoice_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
            $table->string('username')->nullable()->unique()->after('code');
            $table->foreignId('group_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $table->foreignId('default_branch_id')->nullable()->after('group_id')->constrained('branches')->nullOnDelete();
            $table->boolean('is_admin')->default(false)->after('default_branch_id');
            $table->boolean('is_active')->default(true)->after('is_admin');
            $table->string('locale', 5)->default('ar')->after('is_active');
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropConstrainedForeignId('default_branch_id');
            $table->dropColumn(['code', 'username', 'is_admin', 'is_active', 'locale', 'phone']);
        });

        foreach (['egs_units', 'stakeholders', 'activities', 'currencies', 'regions', 'districts', 'cities', 'countries', 'fiscal_years', 'groups', 'warehouses', 'branches', 'companies'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
