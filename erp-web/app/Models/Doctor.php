<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    use HasBilingualName;

    protected $table = 'doctors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function salesDocuments(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'doctor_id');
    }
}
