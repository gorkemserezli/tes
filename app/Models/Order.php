<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\LogsActivity;

class Order extends Model implements Auditable
{
    use HasFactory, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    /**
     * Order statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Payment statuses
     */
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_PAID = 'paid';
    const PAYMENT_FAILED = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_number',
        'user_id',
        'order_type',
        'delivery_type',
        'status',
        'subtotal',
        'vat_total',
        'discount_total',
        'shipping_cost',
        'grand_total',
        'payment_method',
        'payment_status',
        'shipping_address',
        'shipping_contact_name',
        'shipping_contact_phone',
        'billing_address',
        'billing_contact_name',
        'billing_contact_phone',
        'use_different_shipping',
        'is_dropshipping',
        'dropshipping_barcode_pdf',
        'notes',
        'internal_notes',
        'shipped_at',
        'delivered_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal' => 'decimal:2',
        'vat_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'use_different_shipping' => 'boolean',
        'is_dropshipping' => 'boolean',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            // Generate unique order number
            if (empty($order->order_number)) {
                $order->order_number = static::generateOrderNumber();
            }
        });

        static::updated(function ($order) {
            // Log status changes
            if ($order->isDirty('status')) {
                OrderLog::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'action' => 'status_changed',
                    'description' => 'Sipariş durumu değiştirildi',
                    'old_value' => ['status' => $order->getOriginal('status')],
                    'new_value' => ['status' => $order->status],
                ]);
            }

            // Log payment status changes
            if ($order->isDirty('payment_status')) {
                OrderLog::create([
                    'order_id' => $order->id,
                    'user_id' => auth()->id(),
                    'action' => 'payment_status_changed',
                    'description' => 'Ödeme durumu değiştirildi',
                    'old_value' => ['payment_status' => $order->getOriginal('payment_status')],
                    'new_value' => ['payment_status' => $order->payment_status],
                ]);
            }
        });
    }

    /**
     * Generate unique order number.
     */
    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD';
        $date = now()->format('Ymd');

        // Get today's order count
        $count = static::whereDate('created_at', today())->count() + 1;

        return $prefix . $date . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get the user that owns the order.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order items.
     */
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the order logs.
     */
    public function logs()
    {
        return $this->hasMany(OrderLog::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the payment transactions.
     */
    public function payments()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Get the shipment.
     */
    public function shipment()
    {
        return $this->hasOne(Shipment::class);
    }

    /**
     * Get the shipping documents.
     */
    public function shippingDocuments()
    {
        return $this->hasMany(ShippingDocument::class);
    }

    /**
     * Scope for status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for payment status.
     */
    public function scopePaymentStatus($query, $status)
    {
        return $query->where('payment_status', $status);
    }

    /**
     * Scope for date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            self::STATUS_PENDING => 'Beklemede',
            self::STATUS_CONFIRMED => 'Onaylandı',
            self::STATUS_PROCESSING => 'Hazırlanıyor',
            self::STATUS_SHIPPED => 'Kargoya Verildi',
            self::STATUS_DELIVERED => 'Teslim Edildi',
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
            self::STATUS_CONFIRMED => 'blue',
            self::STATUS_PROCESSING => 'indigo',
            self::STATUS_SHIPPED => 'purple',
            self::STATUS_DELIVERED => 'green',
            self::STATUS_CANCELLED => 'red',
        ];

        return $colors[$this->status] ?? 'gray';
    }

    /**
     * Get payment status label.
     */
    public function getPaymentStatusLabelAttribute(): string
    {
        $labels = [
            self::PAYMENT_PENDING => 'Ödeme Bekleniyor',
            self::PAYMENT_PAID => 'Ödendi',
            self::PAYMENT_FAILED => 'Ödeme Başarısız',
            self::PAYMENT_REFUNDED => 'İade Edildi',
        ];

        return $labels[$this->payment_status] ?? $this->payment_status;
    }

    /**
     * Get payment method label.
     */
    public function getPaymentMethodLabelAttribute(): string
    {
        $labels = [
            'credit_card' => 'Kredi Kartı',
            'bank_transfer' => 'Havale/EFT',
            'balance' => 'Cari Bakiye',
        ];

        return $labels[$this->payment_method] ?? $this->payment_method;
    }

    /**
     * Can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED]);
    }

    /**
     * Can be shipped.
     */
    public function canBeShipped(): bool
    {
        return $this->status === self::STATUS_PROCESSING
            && $this->payment_status === self::PAYMENT_PAID;
    }

    /**
     * Cancel order.
     */
    public function cancel(string $reason = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'internal_notes' => $reason ? $this->internal_notes . "\nİptal sebebi: " . $reason : $this->internal_notes,
        ]);

        // Release reserved stock
        foreach ($this->items as $item) {
            $item->product->releaseStock($item->quantity, $this->id);
        }

        // Refund if payment was made
        if ($this->payment_status === self::PAYMENT_PAID) {
            if ($this->payment_method === 'balance') {
                // Refund to balance
                $this->user->company->addBalance(
                    $this->grand_total,
                    'Sipariş iadesi: ' . $this->order_number
                );
            }

            $this->update(['payment_status' => self::PAYMENT_REFUNDED]);
        }

        return true;
    }

    /**
     * Confirm order.
     */
    public function confirm(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $this->update(['status' => self::STATUS_CONFIRMED]);

        // Send notification
        $this->user->notify(new OrderConfirmedNotification($this));

        return true;
    }

    /**
     * Mark as processing.
     */
    public function markAsProcessing(): bool
    {
        if ($this->status !== self::STATUS_CONFIRMED) {
            return false;
        }

        $this->update(['status' => self::STATUS_PROCESSING]);

        return true;
    }

    /**
     * Mark as shipped.
     */
    public function markAsShipped(string $trackingNumber = null): bool
    {
        if (!$this->canBeShipped()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_SHIPPED,
            'shipped_at' => now(),
        ]);

        // Create shipment record if tracking number provided
        if ($trackingNumber) {
            Shipment::create([
                'order_id' => $this->id,
                'tracking_number' => $trackingNumber,
                'carrier' => 'aras',
                'shipped_at' => now(),
            ]);
        }

        // Send notification
        $this->user->notify(new OrderShippedNotification($this));

        return true;
    }

    /**
     * Mark as delivered.
     */
    public function markAsDelivered(): bool
    {
        if ($this->status !== self::STATUS_SHIPPED) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);

        // Update shipment
        if ($this->shipment) {
            $this->shipment->update(['delivered_at' => now()]);
        }

        return true;
    }

    /**
     * Add internal note.
     */
    public function addInternalNote(string $note, User $user = null): void
    {
        $timestamp = now()->format('d.m.Y H:i');
        $userName = $user ? $user->name : 'Sistem';

        $formattedNote = "[{$timestamp}] {$userName}: {$note}";

        $this->update([
            'internal_notes' => $this->internal_notes
                ? $this->internal_notes . "\n" . $formattedNote
                : $formattedNote
        ]);
    }

    /**
     * Get item count.
     */
    public function getItemCountAttribute(): int
    {
        return $this->items()->sum('quantity');
    }

    /**
     * Get unique product count.
     */
    public function getProductCountAttribute(): int
    {
        return $this->items()->count();
    }

    /**
     * Get full shipping address.
     */
    public function getFullShippingAddressAttribute(): string
    {
        $parts = array_filter([
            $this->shipping_contact_name,
            $this->shipping_address,
            $this->shipping_contact_phone ? 'Tel: ' . $this->shipping_contact_phone : null,
        ]);

        return implode("\n", $parts);
    }

    /**
     * Get full billing address.
     */
    public function getFullBillingAddressAttribute(): string
    {
        $parts = array_filter([
            $this->billing_contact_name ?: $this->user->company->company_name,
            $this->billing_address,
            $this->billing_contact_phone ? 'Tel: ' . $this->billing_contact_phone : null,
        ]);

        return implode("\n", $parts);
    }

    /**
     * Check if order is editable.
     */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Calculate totals from items.
     */
    public function calculateTotals(): void
    {
        $subtotal = 0;
        $vatTotal = 0;

        foreach ($this->items as $item) {
            $subtotal += $item->unit_price * $item->quantity;
            $vatTotal += ($item->unit_price * $item->quantity * $item->vat_rate / 100);
        }

        $grandTotal = $subtotal + $vatTotal - $this->discount_total + $this->shipping_cost;

        $this->update([
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'grand_total' => $grandTotal,
        ]);
    }
}
