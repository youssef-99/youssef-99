<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    /** Inactive users cannot log in. A freshly created model without the attribute uses the column default (active). */
    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) ($this->is_active ?? true);
    }

    public function getFilamentName(): string
    {
        return $this->name ?: (string) $this->username;
    }

    /**
     * Check a permission key such as "sales.customers.view".
     * Admins have every permission.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return in_array($key, $this->group?->permissions ?? [], true);
    }

    /** Active sessions other than the given one (used for single-session login). */
    public function otherActiveSessions(?string $exceptSessionId = null): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        $cutoff = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->getKey())
            ->where('last_activity', '>=', $cutoff)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->get();
    }

    public function terminateSessions(): int
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();
    }
}
