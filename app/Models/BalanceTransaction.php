<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class BalanceTransaction extends Model
{
    use HasFactory;

    /**
     * Transaction types
     */
    const TYPE_DEPOSIT = 'deposit';
    const TYPE_WITHDRAW = 'withdraw';
    const TYPE_ORDER_PAYMENT = 'order_payment';
    const TYPE_REFUND = 'refund';
    const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'adjustment_reason',
        'created_by',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaction) {
            // Auto-fill IP and user agent
            if (!$transaction->ip_address) {
                $transaction->ip_address = Request::ip();
            }
            if (!$transaction->user_agent) {
                $transaction->user_agent = Request::userAgent();
            }
        });
    }

    /**
     * Get the user that owns the transaction.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who created the transaction.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reference model (polymorphic)
     */
    public function reference()
    {
        if ($this->reference_type && $this->reference_id) {
            $modelClass = $this->getModelClassFromType($this->reference_type);
            if ($modelClass && class_exists($modelClass)) {
                return $this->belongsTo($modelClass, 'reference_id');
            }
        }
        return null;
    }

    /**
     * Get model class from reference type
     */
    private function getModelClassFromType(string $type): ?string
    {
        $mapping = [
            'orders' => Order::class,
            'payment_transactions' => PaymentTransaction::class,
        ];

        return $mapping[$type] ?? null;
    }

    /**
     * Scope for user transactions
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for transaction type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get formatted amount
     */
    public function getFormattedAmountAttribute(): string
    {
        $prefix = $this->amount >= 0 ? '+' : '';
        return $prefix . number_format($this->amount, 2, ',', '.') . ' ₺';
    }

    /**
     * Get type label
     */
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            self::TYPE_DEPOSIT => 'Bakiye Yükleme',
            self::TYPE_WITHDRAW => 'Bakiye Çekme',
            self::TYPE_ORDER_PAYMENT => 'Sipariş Ödemesi',
            self::TYPE_REFUND => 'İade',
            self::TYPE_MANUAL_ADJUSTMENT => 'Manuel Düzeltme',
        ];

        return $labels[$this->type] ?? $this->type;
    }

    /**
     * Get type color for UI
     */
    public function getTypeColorAttribute(): string
    {
        $colors = [
            self::TYPE_DEPOSIT => 'green',
            self::TYPE_WITHDRAW => 'red',
            self::TYPE_ORDER_PAYMENT => 'blue',
            self::TYPE_REFUND => 'orange',
            self::TYPE_MANUAL_ADJUSTMENT => 'gray',
        ];

        return $colors[$this->type] ?? 'gray';
    }

    /**
     * Check if transaction is income
     */
    public function isIncome(): bool
    {
        return in_array($this->type, [self::TYPE_DEPOSIT, self::TYPE_REFUND]);
    }

    /**
     * Check if transaction is expense
     */
    public function isExpense(): bool
    {
        return in_array($this->type, [self::TYPE_WITHDRAW, self::TYPE_ORDER_PAYMENT]);
    }

    /**
     * Check if transaction is manual
     */
    public function isManual(): bool
    {
        return $this->type === self::TYPE_MANUAL_ADJUSTMENT;
    }
}
