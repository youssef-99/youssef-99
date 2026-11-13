<?php

namespace App\Models;

use App\Enums\StockDocumentType;
use App\Services\StockDocumentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockDocument extends Model
{
    protected $table = 'stock_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'gross_total' => 'decimal:2',
            'items_discount' => 'decimal:2',
            'additional_discount' => 'decimal:2',
            'net_total' => 'decimal:2',
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function relatedDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'related_document_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'salesman_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockDocumentLine::class, 'stock_document_id')->orderBy('id');
    }

    protected static function booted(): void
    {
        static::creating(fn (StockDocument $doc) => StockDocumentService::initialize($doc));
    }

    public function typeEnum(): StockDocumentType
    {
        return StockDocumentType::from($this->type);
    }
}
