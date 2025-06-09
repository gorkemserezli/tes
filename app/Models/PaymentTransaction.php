<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\LogsActivity;

class PaymentTransaction extends Model implements Auditable
{
    use HasFactory, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    /**
     * Payment statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Payment methods
     */
    const METHOD_CREDIT_CARD = 'credit_card';
    const METHOD_BANK_TRANSFER = 'bank_transfer';
    const METHOD_BALANCE = 'balance';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'transaction_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'card_holder_name',
        'masked_card_number',
        'bank_name',
        'bank_receipt',
        'gateway_response',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'gateway_response' => 'array',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'gateway_response',
    ];

    /**
     * Get the order that owns the transaction.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user through order.
     */
    public function user()
    {
        return $this->hasOneThrough(User::class, Order::class, 'id', 'id', 'order_id', 'user_id');
    }

    /**
     * Scope for successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * Scope for pending transactions.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for failed transactions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            self::STATUS_PENDING => 'Beklemede',
            self::STATUS_SUCCESS => 'Başarılı',
            self::STATUS_FAILED => 'Başarısız',
            self::STATUS_CANCELLED => 'İptal Edildi',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        $colors = [
            self::STATUS_PENDING => 'yellow',
            self::STATUS_SUCCESS => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_CANCELLED => 'gray',
        ];

        return $colors[$this->status] ?? 'gray';
    }

    /**
     * Get payment method label.
     */
    public function getPaymentMethodLabelAttribute(): string
    {
        $labels = [
            self::METHOD_CREDIT_CARD => 'Kredi Kartı',
            self::METHOD_BANK_TRANSFER => 'Havale/EFT',
            self::METHOD_BALANCE => 'Cari Bakiye',
        ];

        return $labels[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Check if transaction is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * Check if transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if transaction is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark as successful.
     */
    public function markAsSuccessful(array $gatewayResponse = []): bool
    {
        return $this->update([
            'status' => self::STATUS_SUCCESS,
            'processed_at' => now(),
            'gateway_response' => array_merge($this->gateway_response ?? [], $gatewayResponse),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(array $gatewayResponse = []): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'gateway_response' => array_merge($this->gateway_response ?? [], $gatewayResponse),
        ]);
    }

    /**
     * Mark as cancelled.
     */
    public function markAsCancelled(string $reason = null): bool
    {
        $gatewayResponse = $this->gateway_response ?? [];

        if ($reason) {
            $gatewayResponse['cancellation_reason'] = $reason;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'processed_at' => now(),
            'gateway_response' => $gatewayResponse,
        ]);
    }

    /**
     * Process balance payment.
     */
    public function processBalancePayment(): bool
    {
        $order = $this->order;
        $user = $order->user;
        $company = $user->company;

        // Check balance
        if (!$company->hasBalance($this->amount)) {
            $this->markAsFailed(['error' => 'Yetersiz bakiye']);
            return false;
        }

        // Deduct balance
        $balanceTransaction = $company->deductBalance(
            $this->amount,
            'Sipariş ödemesi: ' . $order->order_number,
            'orders',
            $order->id
        );

        if (!$balanceTransaction) {
            $this->markAsFailed(['error' => 'Bakiye düşülemedi']);
            return false;
        }

        // Mark as successful
        $this->markAsSuccessful([
            'balance_transaction_id' => $balanceTransaction->id,
            'balance_before' => $balanceTransaction->balance_before,
            'balance_after' => $balanceTransaction->balance_after,
        ]);

        // Update order payment status
        $order->update(['payment_status' => Order::PAYMENT_PAID]);

        // Log payment
        OrderLog::logPayment($order, $this->amount, $this->payment_method);

        // Send notification
        Notification::createPaymentNotification(
            $user,
            Notification::TYPE_PAYMENT_RECEIVED,
            $this->amount
        );

        return true;
    }

    /**
     * Process bank transfer payment.
     */
    public function processBankTransfer(string $bankName, string $receiptPath): bool
    {
        $this->update([
            'bank_name' => $bankName,
            'bank_receipt' => $receiptPath,
        ]);

        // Bank transfers need manual approval
        // Just log the receipt upload
        OrderLog::create([
            'order_id' => $this->order_id,
            'user_id' => auth()->id(),
            'action' => 'bank_receipt_uploaded',
            'description' => 'Havale dekontu yüklendi',
            'new_value' => [
                'bank' => $bankName,
                'receipt' => $receiptPath,
            ],
        ]);

        return true;
    }

    /**
     * Get formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2, ',', '.') . ' ' . $this->currency;
    }

    /**
     * Get display info for payment method.
     */
    public function getPaymentDisplayInfoAttribute(): string
    {
        switch ($this->payment_method) {
            case self::METHOD_CREDIT_CARD:
                if ($this->masked_card_number) {
                    return $this->masked_card_number;
                }
                break;

            case self::METHOD_BANK_TRANSFER:
                if ($this->bank_name) {
                    return $this->bank_name;
                }
                break;

            case self::METHOD_BALANCE:
                return 'Cari Bakiye';
        }

        return $this->payment_method_label;
    }

    /**
     * Get receipt URL.
     */
    public function getReceiptUrlAttribute(): ?string
    {
        if ($this->bank_receipt) {
            return asset('storage/' . $this->bank_receipt);
        }

        return null;
    }

    /**
     * Create transaction for order.
     */
    public static function createForOrder(Order $order, string $paymentMethod, array $data = []): self
    {
        return static::create(array_merge([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod,
            'amount' => $order->grand_total,
            'currency' => 'TRY',
            'status' => self::STATUS_PENDING,
        ], $data));
    }
}
