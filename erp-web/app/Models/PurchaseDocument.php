<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDocument extends Model
{
    protected $table = 'purchase_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'receipt_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'shipping_cost' => 'decimal:2',
            'purchase_expenses' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'items_discount' => 'decimal:2',
            'additional_discount' => 'decimal:2',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
            'rasd_sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function accreditation(): BelongsTo
    {
        return $this->belongsTo(Accreditation::class, 'accreditation_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'purchase_order_id');
    }

    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'original_document_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function cashSupplier(): BelongsTo
    {
        return $this->belongsTo(CashSupplier::class, 'cash_supplier_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLine::class, 'purchase_document_id')->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseDocument::class, 'original_document_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (PurchaseDocument $doc) => \App\Services\PurchaseDocumentService::initialize($doc));
    }

    public function typeEnum(): \App\Enums\PurchaseDocumentType
    {
        return \App\Enums\PurchaseDocumentType::from($this->type);
    }
}
