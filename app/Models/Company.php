<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\LogsActivity;

class Company extends Model implements Auditable
{
    use HasFactory, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_name',
        'tax_number',
        'tax_office',
        'address',
        'city',
        'district',
        'postal_code',
        'is_approved',
        'approved_at',
        'approved_by',
        'credit_limit',
        'remaining_credit',
        'balance',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_approved' => 'boolean',
        'approved_at' => 'datetime',
        'credit_limit' => 'decimal:2',
        'remaining_credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    /**
     * Attributes to include in the Audit.
     *
     * @var array
     */
    protected $auditInclude = [
        'company_name',
        'tax_number',
        'tax_office',
        'address',
        'city',
        'district',
        'postal_code',
        'is_approved',
        'credit_limit',
        'remaining_credit',
        'balance',
    ];

    /**
     * Get the user that owns the company.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who approved the company.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get balance transactions
     */
    public function balanceTransactions()
    {
        return $this->hasManyThrough(
            BalanceTransaction::class,
            User::class,
            'id', // Foreign key on users table
            'user_id', // Foreign key on balance_transactions table
            'user_id', // Local key on companies table
            'id' // Local key on users table
        );
    }

    /**
     * Scope for approved companies
     */
    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    /**
     * Scope for pending companies
     */
    public function scopePending($query)
    {
        return $query->where('is_approved', false);
    }

    /**
     * Approve the company
     */
    public function approve(User $approvedBy): bool
    {
        return $this->update([
            'is_approved' => true,
            'approved_at' => now(),
            'approved_by' => $approvedBy->id,
        ]);
    }

    /**
     * Reject the company
     */
    public function reject(): bool
    {
        return $this->update([
            'is_approved' => false,
            'approved_at' => null,
            'approved_by' => null,
        ]);
    }

    /**
     * Add balance
     */
    public function addBalance(float $amount, string $description, ?User $createdBy = null): BalanceTransaction
    {
        $transaction = BalanceTransaction::create([
            'user_id' => $this->user_id,
            'type' => 'deposit',
            'amount' => $amount,
            'balance_before' => $this->balance,
            'balance_after' => $this->balance + $amount,
            'description' => $description,
            'created_by' => $createdBy?->id,
        ]);

        $this->update(['balance' => $this->balance + $amount]);

        return $transaction;
    }

    /**
     * Deduct balance
     */
    public function deductBalance(float $amount, string $description, string $referenceType = null, int $referenceId = null): ?BalanceTransaction
    {
        if ($this->balance < $amount) {
            return null;
        }

        $transaction = BalanceTransaction::create([
            'user_id' => $this->user_id,
            'type' => 'withdraw',
            'amount' => -$amount,
            'balance_before' => $this->balance,
            'balance_after' => $this->balance - $amount,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description,
        ]);

        $this->update(['balance' => $this->balance - $amount]);

        return $transaction;
    }

    /**
     * Manual balance adjustment
     */
    public function adjustBalance(float $newBalance, string $reason, User $adjustedBy): BalanceTransaction
    {
        $difference = $newBalance - $this->balance;

        $transaction = BalanceTransaction::create([
            'user_id' => $this->user_id,
            'type' => 'manual_adjustment',
            'amount' => $difference,
            'balance_before' => $this->balance,
            'balance_after' => $newBalance,
            'description' => 'Manuel bakiye düzeltmesi',
            'adjustment_reason' => $reason,
            'created_by' => $adjustedBy->id,
        ]);

        $this->update(['balance' => $newBalance]);

        return $transaction;
    }

    /**
     * Check if balance is sufficient
     */
    public function hasBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    /**
     * Update credit limit
     */
    public function updateCreditLimit(float $newLimit): bool
    {
        $difference = $newLimit - $this->credit_limit;

        return $this->update([
            'credit_limit' => $newLimit,
            'remaining_credit' => $this->remaining_credit + $difference,
        ]);
    }

    /**
     * Use credit
     */
    public function useCredit(float $amount): bool
    {
        if ($this->remaining_credit < $amount) {
            return false;
        }

        return $this->update([
            'remaining_credit' => $this->remaining_credit - $amount,
        ]);
    }

    /**
     * Restore credit
     */
    public function restoreCredit(float $amount): bool
    {
        return $this->update([
            'remaining_credit' => min($this->remaining_credit + $amount, $this->credit_limit),
        ]);
    }

    /**
     * Get full address
     */
    public function getFullAddressAttribute(): string
    {
        return implode(', ', array_filter([
            $this->address,
            $this->district,
            $this->city,
            $this->postal_code
        ]));
    }
}
