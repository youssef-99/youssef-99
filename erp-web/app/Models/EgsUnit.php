<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ZATCA EGS unit. The private key and the CSID secrets are encrypted at rest and never serialised.
 */
class EgsUnit extends Model
{
    /** Encrypted columns (see the 2026_02_01_450002 data migration). */
    public const SECRETS = ['private_key', 'compliance_secret', 'production_secret'];

    protected $table = 'egs_units';

    protected $guarded = ['id'];

    protected $hidden = self::SECRETS;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'private_key' => 'encrypted',
            'compliance_secret' => 'encrypted',
            'production_secret' => 'encrypted',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
