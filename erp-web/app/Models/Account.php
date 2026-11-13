<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Services\JournalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasBilingualName;

    protected $table = 'accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    /** @return array{opening: float, debit: float, credit: float, closing: float} */
    public function balance(?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        return JournalService::balance($this->descendantIds(), $from, $to, $branchId);
    }

    /** This account and all descendants (for parent-account balances). */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $frontier);
        }

        return $ids;
    }

    protected static function booted(): void
    {
        static::saving(function (Account $account) {
            if ($account->parent_id && ! $account->isDirty('level')) {
                $account->level = (int) ($account->parent?->level ?? 0) + 1;
            }
        });
        static::saved(function (Account $account) {
            if ($account->parent_id) {
                static::query()->whereKey($account->parent_id)->update(['is_postable' => false]);
            }
        });
    }
}
