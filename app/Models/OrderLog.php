<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class OrderLog extends Model
{
    use HasFactory;

    /**
     * Action types
     */
    const ACTION_CREATED = 'created';
    const ACTION_STATUS_CHANGED = 'status_changed';
    const ACTION_PAYMENT_STATUS_CHANGED = 'payment_status_changed';
    const ACTION_PAYMENT_RECEIVED = 'payment_received';
    const ACTION_SHIPPED = 'shipped';
    const ACTION_DELIVERED = 'delivered';
    const ACTION_CANCELLED = 'cancelled';
    const ACTION_NOTE_ADDED = 'note_added';
    const ACTION_DOCUMENT_UPLOADED = 'document_uploaded';
    const ACTION_ITEM_ADDED = 'item_added';
    const ACTION_ITEM_REMOVED = 'item_removed';
    const ACTION_ITEM_UPDATED = 'item_updated';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'user_id',
        'action',
        'description',
        'old_value',
        'new_value',
        'metadata',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'metadata' => 'array',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            // Auto-fill IP and user agent
            if (!$log->ip_address) {
                $log->ip_address = Request::ip();
            }
            if (!$log->user_agent) {
                $log->user_agent = Request::userAgent();
            }

            // Add metadata
            if (!$log->metadata) {
                $log->metadata = [
                    'timestamp' => now()->toIso8601String(),
                    'environment' => app()->environment(),
                ];
            }
        });
    }

    /**
     * Get the order that owns the log.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the user who created the log.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get action label.
     */
    public function getActionLabelAttribute(): string
    {
        $labels = [
            self::ACTION_CREATED => 'Sipariş Oluşturuldu',
            self::ACTION_STATUS_CHANGED => 'Durum Değiştirildi',
            self::ACTION_PAYMENT_STATUS_CHANGED => 'Ödeme Durumu Değiştirildi',
            self::ACTION_PAYMENT_RECEIVED => 'Ödeme Alındı',
            self::ACTION_SHIPPED => 'Kargoya Verildi',
            self::ACTION_DELIVERED => 'Teslim Edildi',
            self::ACTION_CANCELLED => 'İptal Edildi',
            self::ACTION_NOTE_ADDED => 'Not Eklendi',
            self::ACTION_DOCUMENT_UPLOADED => 'Belge Yüklendi',
            self::ACTION_ITEM_ADDED => 'Ürün Eklendi',
            self::ACTION_ITEM_REMOVED => 'Ürün Çıkarıldı',
            self::ACTION_ITEM_UPDATED => 'Ürün Güncellendi',
        ];

        return $labels[$this->action] ?? $this->action;
    }

    /**
     * Get action icon.
     */
    public function getActionIconAttribute(): string
    {
        $icons = [
            self::ACTION_CREATED => 'plus-circle',
            self::ACTION_STATUS_CHANGED => 'refresh',
            self::ACTION_PAYMENT_STATUS_CHANGED => 'credit-card',
            self::ACTION_PAYMENT_RECEIVED => 'check-circle',
            self::ACTION_SHIPPED => 'truck',
            self::ACTION_DELIVERED => 'badge-check',
            self::ACTION_CANCELLED => 'x-circle',
            self::ACTION_NOTE_ADDED => 'annotation',
            self::ACTION_DOCUMENT_UPLOADED => 'document-add',
            self::ACTION_ITEM_ADDED => 'plus',
            self::ACTION_ITEM_REMOVED => 'minus',
            self::ACTION_ITEM_UPDATED => 'pencil',
        ];

        return $icons[$this->action] ?? 'information-circle';
    }

    /**
     * Get action color.
     */
    public function getActionColorAttribute(): string
    {
        $colors = [
            self::ACTION_CREATED => 'green',
            self::ACTION_STATUS_CHANGED => 'blue',
            self::ACTION_PAYMENT_STATUS_CHANGED => 'indigo',
            self::ACTION_PAYMENT_RECEIVED => 'green',
            self::ACTION_SHIPPED => 'purple',
            self::ACTION_DELIVERED => 'green',
            self::ACTION_CANCELLED => 'red',
            self::ACTION_NOTE_ADDED => 'gray',
            self::ACTION_DOCUMENT_UPLOADED => 'yellow',
            self::ACTION_ITEM_ADDED => 'green',
            self::ACTION_ITEM_REMOVED => 'red',
            self::ACTION_ITEM_UPDATED => 'yellow',
        ];

        return $colors[$this->action] ?? 'gray';
    }

    /**
     * Get formatted description.
     */
    public function getFormattedDescriptionAttribute(): string
    {
        $userName = $this->user ? $this->user->name : 'Sistem';

        // Add specific details based on action
        switch ($this->action) {
            case self::ACTION_STATUS_CHANGED:
                if ($this->old_value && $this->new_value) {
                    return sprintf(
                        '%s tarafından sipariş durumu "%s" → "%s" olarak değiştirildi.',
                        $userName,
                        $this->old_value['status'] ?? '?',
                        $this->new_value['status'] ?? '?'
                    );
                }
                break;

            case self::ACTION_PAYMENT_STATUS_CHANGED:
                if ($this->old_value && $this->new_value) {
                    return sprintf(
                        '%s tarafından ödeme durumu "%s" → "%s" olarak değiştirildi.',
                        $userName,
                        $this->old_value['payment_status'] ?? '?',
                        $this->new_value['payment_status'] ?? '?'
                    );
                }
                break;

            case self::ACTION_DOCUMENT_UPLOADED:
                if ($this->new_value && isset($this->new_value['document_type'])) {
                    return sprintf(
                        '%s tarafından %s yüklendi.',
                        $userName,
                        $this->new_value['document_type']
                    );
                }
                break;
        }

        return $userName . ' - ' . $this->description;
    }

    /**
     * Create log for order creation.
     */
    public static function logCreation(Order $order, User $user = null): self
    {
        return static::create([
            'order_id' => $order->id,
            'user_id' => $user?->id ?? $order->user_id,
            'action' => self::ACTION_CREATED,
            'description' => 'Sipariş oluşturuldu',
            'new_value' => [
                'order_number' => $order->order_number,
                'total' => $order->grand_total,
                'payment_method' => $order->payment_method,
            ],
        ]);
    }

    /**
     * Create log for status change.
     */
    public static function logStatusChange(Order $order, string $oldStatus, string $newStatus, User $user = null): self
    {
        return static::create([
            'order_id' => $order->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => self::ACTION_STATUS_CHANGED,
            'description' => 'Sipariş durumu değiştirildi',
            'old_value' => ['status' => $oldStatus],
            'new_value' => ['status' => $newStatus],
        ]);
    }

    /**
     * Create log for payment.
     */
    public static function logPayment(Order $order, float $amount, string $method, User $user = null): self
    {
        return static::create([
            'order_id' => $order->id,
            'user_id' => $user?->id ?? auth()->id(),
            'action' => self::ACTION_PAYMENT_RECEIVED,
            'description' => 'Ödeme alındı',
            'new_value' => [
                'amount' => $amount,
                'method' => $method,
            ],
        ]);
    }
}
