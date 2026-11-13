<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocument extends Model
{
    protected $table = 'sales_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'gross_total' => 'decimal:2',
            'items_discount' => 'decimal:2',
            'additional_discount' => 'decimal:2',
            'additional_discount_percent' => 'decimal:4',
            'net_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'cash_amount' => 'decimal:2',
            'card_amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'change_due' => 'decimal:2',
            'is_paid' => 'boolean',
            'is_external' => 'boolean',
            'needs_issue_transfer' => 'boolean',
            'points_earned' => 'decimal:2',
            'points_redeemed' => 'decimal:2',
            'is_posted' => 'boolean',
            'posted_at' => 'datetime',
            'zatca_submitted_at' => 'datetime',
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

    public function receivedFromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'received_from_branch_id');
    }

    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'original_document_id');
    }

    public function salesPolicy(): BelongsTo
    {
        return $this->belongsTo(SalesPolicy::class, 'sales_policy_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class, 'salesman_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function pointsCustomer(): BelongsTo
    {
        return $this->belongsTo(PointsCustomer::class, 'points_customer_id');
    }

    public function cashCustomer(): BelongsTo
    {
        return $this->belongsTo(CashCustomer::class, 'cash_customer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
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
        return $this->hasMany(SalesDocumentLine::class, 'sales_document_id')->orderBy('id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'original_document_id');
    }

    protected static function booted(): void
    {
        static::creating(fn (SalesDocument $doc) => \App\Services\SalesDocumentService::initialize($doc));
    }

    public function typeEnum(): \App\Enums\SalesDocumentType
    {
        return \App\Enums\SalesDocumentType::from($this->type);
    }
}
