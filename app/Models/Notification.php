<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    /**
     * Notification types
     */
    const TYPE_ORDER_CREATED = 'order_created';
    const TYPE_ORDER_CONFIRMED = 'order_confirmed';
    const TYPE_ORDER_SHIPPED = 'order_shipped';
    const TYPE_ORDER_DELIVERED = 'order_delivered';
    const TYPE_ORDER_CANCELLED = 'order_cancelled';
    const TYPE_PAYMENT_RECEIVED = 'payment_received';
    const TYPE_PAYMENT_FAILED = 'payment_failed';
    const TYPE_ACCOUNT_APPROVED = 'account_approved';
    const TYPE_ACCOUNT_REJECTED = 'account_rejected';
    const TYPE_BALANCE_ADDED = 'balance_added';
    const TYPE_LOW_STOCK = 'low_stock';
    const TYPE_PRICE_CHANGE = 'price_change';
    const TYPE_NEW_PRODUCT = 'new_product';
    const TYPE_SYSTEM = 'system';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    /**
     * Get the user that owns the notification.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark as read.
     */
    public function markAsRead(): bool
    {
        if ($this->is_read) {
            return true;
        }

        return $this->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Mark as unread.
     */
    public function markAsUnread(): bool
    {
        return $this->update([
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    /**
     * Scope for unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope for read notifications.
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Get type label.
     */
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            self::TYPE_ORDER_CREATED => 'Yeni Sipariş',
            self::TYPE_ORDER_CONFIRMED => 'Sipariş Onaylandı',
            self::TYPE_ORDER_SHIPPED => 'Sipariş Kargoda',
            self::TYPE_ORDER_DELIVERED => 'Sipariş Teslim Edildi',
            self::TYPE_ORDER_CANCELLED => 'Sipariş İptal Edildi',
            self::TYPE_PAYMENT_RECEIVED => 'Ödeme Alındı',
            self::TYPE_PAYMENT_FAILED => 'Ödeme Başarısız',
            self::TYPE_ACCOUNT_APPROVED => 'Hesap Onaylandı',
            self::TYPE_ACCOUNT_REJECTED => 'Hesap Reddedildi',
            self::TYPE_BALANCE_ADDED => 'Bakiye Eklendi',
            self::TYPE_LOW_STOCK => 'Düşük Stok',
            self::TYPE_PRICE_CHANGE => 'Fiyat Değişikliği',
            self::TYPE_NEW_PRODUCT => 'Yeni Ürün',
            self::TYPE_SYSTEM => 'Sistem',
        ];

        return $labels[$this->type] ?? $this->type;
    }

    /**
     * Get type icon.
     */
    public function getTypeIconAttribute(): string
    {
        $icons = [
            self::TYPE_ORDER_CREATED => 'shopping-cart',
            self::TYPE_ORDER_CONFIRMED => 'check-circle',
            self::TYPE_ORDER_SHIPPED => 'truck',
            self::TYPE_ORDER_DELIVERED => 'badge-check',
            self::TYPE_ORDER_CANCELLED => 'x-circle',
            self::TYPE_PAYMENT_RECEIVED => 'cash',
            self::TYPE_PAYMENT_FAILED => 'exclamation-circle',
            self::TYPE_ACCOUNT_APPROVED => 'user-check',
            self::TYPE_ACCOUNT_REJECTED => 'user-x',
            self::TYPE_BALANCE_ADDED => 'currency-dollar',
            self::TYPE_LOW_STOCK => 'exclamation',
            self::TYPE_PRICE_CHANGE => 'tag',
            self::TYPE_NEW_PRODUCT => 'sparkles',
            self::TYPE_SYSTEM => 'information-circle',
        ];

        return $icons[$this->type] ?? 'bell';
    }

    /**
     * Get type color.
     */
    public function getTypeColorAttribute(): string
    {
        $colors = [
            self::TYPE_ORDER_CREATED => 'blue',
            self::TYPE_ORDER_CONFIRMED => 'green',
            self::TYPE_ORDER_SHIPPED => 'purple',
            self::TYPE_ORDER_DELIVERED => 'green',
            self::TYPE_ORDER_CANCELLED => 'red',
            self::TYPE_PAYMENT_RECEIVED => 'green',
            self::TYPE_PAYMENT_FAILED => 'red',
            self::TYPE_ACCOUNT_APPROVED => 'green',
            self::TYPE_ACCOUNT_REJECTED => 'red',
            self::TYPE_BALANCE_ADDED => 'green',
            self::TYPE_LOW_STOCK => 'yellow',
            self::TYPE_PRICE_CHANGE => 'indigo',
            self::TYPE_NEW_PRODUCT => 'blue',
            self::TYPE_SYSTEM => 'gray',
        ];

        return $colors[$this->type] ?? 'gray';
    }

    /**
     * Get action URL.
     */
    public function getActionUrlAttribute(): ?string
    {
        if (!$this->data || !isset($this->data['action_url'])) {
            return null;
        }

        return $this->data['action_url'];
    }

    /**
     * Create order notification.
     */
    public static function createOrderNotification(Order $order, string $type, string $message = null): self
    {
        $titles = [
            self::TYPE_ORDER_CREATED => 'Siparişiniz Alındı',
            self::TYPE_ORDER_CONFIRMED => 'Siparişiniz Onaylandı',
            self::TYPE_ORDER_SHIPPED => 'Siparişiniz Kargoya Verildi',
            self::TYPE_ORDER_DELIVERED => 'Siparişiniz Teslim Edildi',
            self::TYPE_ORDER_CANCELLED => 'Siparişiniz İptal Edildi',
        ];

        $defaultMessages = [
            self::TYPE_ORDER_CREATED => 'Siparişiniz başarıyla alındı. Sipariş numaranız: ' . $order->order_number,
            self::TYPE_ORDER_CONFIRMED => 'Siparişiniz onaylandı ve hazırlanmaya başlandı.',
            self::TYPE_ORDER_SHIPPED => 'Siparişiniz kargoya verildi. Takip numaranız: ' . $order->shipment?->tracking_number,
            self::TYPE_ORDER_DELIVERED => 'Siparişiniz başarıyla teslim edildi.',
            self::TYPE_ORDER_CANCELLED => 'Siparişiniz iptal edildi.',
        ];

        return static::create([
            'user_id' => $order->user_id,
            'type' => $type,
            'title' => $titles[$type] ?? 'Sipariş Bildirimi',
            'message' => $message ?? $defaultMessages[$type] ?? '',
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'action_url' => '/orders/' . $order->order_number,
            ],
        ]);
    }

    /**
     * Create payment notification.
     */
    public static function createPaymentNotification(User $user, string $type, float $amount, string $message = null): self
    {
        $titles = [
            self::TYPE_PAYMENT_RECEIVED => 'Ödeme Alındı',
            self::TYPE_PAYMENT_FAILED => 'Ödeme Başarısız',
            self::TYPE_BALANCE_ADDED => 'Bakiye Eklendi',
        ];

        $defaultMessages = [
            self::TYPE_PAYMENT_RECEIVED => number_format($amount, 2, ',', '.') . ' ₺ tutarında ödemeniz alındı.',
            self::TYPE_PAYMENT_FAILED => 'Ödeme işleminiz başarısız oldu. Lütfen tekrar deneyin.',
            self::TYPE_BALANCE_ADDED => 'Hesabınıza ' . number_format($amount, 2, ',', '.') . ' ₺ bakiye eklendi.',
        ];

        return static::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $titles[$type] ?? 'Ödeme Bildirimi',
            'message' => $message ?? $defaultMessages[$type] ?? '',
            'data' => [
                'amount' => $amount,
                'action_url' => '/balance',
            ],
        ]);
    }

    /**
     * Create account notification.
     */
    public static function createAccountNotification(User $user, string $type, string $message = null): self
    {
        $titles = [
            self::TYPE_ACCOUNT_APPROVED => 'Hesabınız Onaylandı',
            self::TYPE_ACCOUNT_REJECTED => 'Hesabınız Reddedildi',
        ];

        $defaultMessages = [
            self::TYPE_ACCOUNT_APPROVED => 'Hesabınız başarıyla onaylandı. Artık alışveriş yapabilirsiniz.',
            self::TYPE_ACCOUNT_REJECTED => 'Hesabınız maalesef onaylanmadı. Lütfen bizimle iletişime geçin.',
        ];

        return static::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $titles[$type] ?? 'Hesap Bildirimi',
            'message' => $message ?? $defaultMessages[$type] ?? '',
            'data' => [
                'action_url' => '/profile',
            ],
        ]);
    }
}
