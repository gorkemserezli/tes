<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Shipment extends Model
{
    use HasFactory, LogsActivity;

    /**
     * Shipment statuses
     */
    const STATUS_CREATED = 'created';
    const STATUS_PICKED_UP = 'picked_up';
    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_RETURNED = 'returned';
    const STATUS_LOST = 'lost';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'carrier',
        'tracking_number',
        'status',
        'status_description',
        'weight',
        'desi',
        'barcode_pdf',
        'is_dropshipping',
        'shipped_at',
        'delivered_at',
        'delivery_signature',
        'last_update_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'weight' => 'decimal:3',
        'desi' => 'decimal:3',
        'is_dropshipping' => 'boolean',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'last_update_at' => 'datetime',
    ];

    /**
     * Get the order that owns the shipment.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the shipping documents.
     */
    public function documents()
    {
        return $this->hasMany(ShippingDocument::class);
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        $labels = [
            self::STATUS_CREATED => 'Oluşturuldu',
            self::STATUS_PICKED_UP => 'Teslim Alındı',
            self::STATUS_IN_TRANSIT => 'Yolda',
            self::STATUS_OUT_FOR_DELIVERY => 'Dağıtımda',
            self::STATUS_DELIVERED => 'Teslim Edildi',
            self::STATUS_RETURNED => 'İade Edildi',
            self::STATUS_LOST => 'Kayıp',
        ];

        return $labels[$this->status] ?? $this->status ?? 'Bilinmiyor';
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        $colors = [
            self::STATUS_CREATED => 'gray',
            self::STATUS_PICKED_UP => 'blue',
            self::STATUS_IN_TRANSIT => 'indigo',
            self::STATUS_OUT_FOR_DELIVERY => 'purple',
            self::STATUS_DELIVERED => 'green',
            self::STATUS_RETURNED => 'yellow',
            self::STATUS_LOST => 'red',
        ];

        return $colors[$this->status] ?? 'gray';
    }

    /**
     * Get carrier label.
     */
    public function getCarrierLabelAttribute(): string
    {
        $labels = [
            'aras' => 'Aras Kargo',
            'mng' => 'MNG Kargo',
            'yurtici' => 'Yurtiçi Kargo',
            'ups' => 'UPS',
            'dhl' => 'DHL',
            'other' => 'Diğer',
        ];

        return $labels[$this->carrier] ?? $this->carrier;
    }

    /**
     * Get tracking URL.
     */
    public function getTrackingUrlAttribute(): ?string
    {
        if (!$this->tracking_number) {
            return null;
        }

        $urls = [
            'aras' => 'https://www.araskargo.com.tr/trmKargomNerede.aspx?q=' . $this->tracking_number,
            'mng' => 'https://www.mngkargo.com.tr/wps/portal/kargotakip?barcode=' . $this->tracking_number,
            'yurtici' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . $this->tracking_number,
            'ups' => 'https://www.ups.com/track?tracknum=' . $this->tracking_number,
            'dhl' => 'https://www.dhl.com/tr-tr/home/tracking.html?tracking-id=' . $this->tracking_number,
        ];

        return $urls[$this->carrier] ?? null;
    }

    /**
     * Update status.
     */
    public function updateStatus(string $status, string $description = null): bool
    {
        $oldStatus = $this->status;

        $updated = $this->update([
            'status' => $status,
            'status_description' => $description,
            'last_update_at' => now(),
        ]);

        if ($updated && $oldStatus !== $status) {
            // Log status change
            OrderLog::create([
                'order_id' => $this->order_id,
                'user_id' => auth()->id(),
                'action' => 'shipment_status_changed',
                'description' => 'Kargo durumu güncellendi',
                'old_value' => ['status' => $oldStatus],
                'new_value' => ['status' => $status, 'description' => $description],
            ]);

            // Update order status if needed
            if ($status === self::STATUS_DELIVERED) {
                $this->order->markAsDelivered();
            }
        }

        return $updated;
    }

    /**
     * Mark as delivered.
     */
    public function markAsDelivered(string $signature = null): bool
    {
        return $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
            'delivery_signature' => $signature,
            'last_update_at' => now(),
        ]);
    }

    /**
     * Calculate desi weight.
     */
    public function calculateDesi(float $width, float $height, float $depth): float
    {
        // Desi = (Width x Height x Depth) / 3000
        return ($width * $height * $depth) / 3000;
    }

    /**
     * Get barcode PDF URL.
     */
    public function getBarcodePdfUrlAttribute(): ?string
    {
        if ($this->barcode_pdf) {
            return asset('storage/' . $this->barcode_pdf);
        }

        return null;
    }

    /**
     * Check if shipment is delivered.
     */
    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }

    /**
     * Check if shipment is trackable.
     */
    public function isTrackable(): bool
    {
        return !empty($this->tracking_number) && !in_array($this->status, [
                self::STATUS_DELIVERED,
                self::STATUS_RETURNED,
                self::STATUS_LOST,
            ]);
    }

    /**
     * Get estimated delivery date.
     */
    public function getEstimatedDeliveryAttribute(): ?string
    {
        if ($this->isDelivered() || !$this->shipped_at) {
            return null;
        }

        // Estimate based on carrier and location
        $businessDays = match($this->carrier) {
            'aras' => 2,
            'mng' => 2,
            'yurtici' => 3,
            'ups' => 1,
            'dhl' => 1,
            default => 3,
        };

        $estimatedDate = $this->shipped_at->copy();

        while ($businessDays > 0) {
            $estimatedDate->addDay();

            // Skip weekends
            if (!$estimatedDate->isWeekend()) {
                $businessDays--;
            }
        }

        return $estimatedDate->format('d.m.Y');
    }
}
