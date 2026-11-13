<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Encrypt the EGS unit private key and CSID secrets stored in plain text (idempotent: values that already
 * decrypt are left alone).
 */
return new class extends Migration
{
    /** @var array<int, string> */
    protected array $columns = ['private_key', 'compliance_secret', 'production_secret'];

    public function up(): void
    {
        DB::table('egs_units')->orderBy('id')->get(['id', ...$this->columns])->each(function (object $row) {
            $updates = [];
            foreach ($this->columns as $column) {
                $value = $row->{$column};
                if ($value === null || $value === '' || $this->isEncrypted($value)) {
                    continue;
                }
                $updates[$column] = Crypt::encryptString($value);
            }
            if ($updates !== []) {
                DB::table('egs_units')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        DB::table('egs_units')->orderBy('id')->get(['id', ...$this->columns])->each(function (object $row) {
            $updates = [];
            foreach ($this->columns as $column) {
                $value = $row->{$column};
                if ($value !== null && $value !== '' && $this->isEncrypted($value)) {
                    $updates[$column] = Crypt::decryptString($value);
                }
            }
            if ($updates !== []) {
                DB::table('egs_units')->where('id', $row->id)->update($updates);
            }
        });
    }

    protected function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
