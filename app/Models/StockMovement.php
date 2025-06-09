<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class StockMovement extends Model
{
    use HasFactory;

    /**
     * Movement types
     */
    const TYPE_IN = 'in';
    const TYPE_OUT = 'out';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_RETURN = 'return';
    const TYPE_RESERVED = 'reserved';
    const TYPE_CANCELLED = 'cancelled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'type',
        'quantity',
        'stock_before',
        'stock_after',
        'reference_type',
        'reference_id',
        'description',
        'unit_cost',
        'created_by',
        'ip_address',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($movement) {
            // Auto-fill IP address
            if (!$movement->ip_address) {
                $movement->ip_address = Request::ip();
            }
        });
    }

    /**
     * Get the product that owns the movement.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created the movement.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reference model (polymorphic).
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
     * Get model class from reference type.
     */
    private function getModelClassFromType(string $type): ?string
    {
        $mapping = [
            'orders' => Order::class,
            'returns' => OrderReturn::class,
            'manual' => null,
        ];

        return $mapping[$type] ?? null;
    }

    /**
     * Scope for product movements.
     */
    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope for movement type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get type label.
     */
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            self::TYPE_IN => 'Stok Girişi',
            self::TYPE_OUT => 'Stok Çıkışı',
            self::TYPE_ADJUSTMENT => 'Stok Düzeltme',
            self::TYPE_RETURN => 'İade',
            self::TYPE_RESERVED => 'Rezerve',
            self::TYPE_CANCELLED => 'İptal',
        ];

        return $labels[$this->type] ?? $this->type;
    }

    /**
     * Get type color.
     */
    public function getTypeColorAttribute(): string
    {
        $colors = [
            self::TYPE_IN => 'green',
            self::TYPE_OUT => 'red',
            self::TYPE_ADJUSTMENT => 'yellow',
            self::TYPE_RETURN => 'blue',
            self::TYPE_RESERVED => 'orange',
            self::TYPE_CANCELLED => 'gray',
        ];

        return $colors[$this->type] ?? 'gray';
    }

    /**
     * Get type icon.
     */
    public function getTypeIconAttribute(): string
    {
        $icons = [
            self::TYPE_IN => 'arrow-down',
            self::TYPE_OUT => 'arrow-up',
            self::TYPE_ADJUSTMENT => 'adjustments',
            self::TYPE_RETURN => 'reply',
            self::TYPE_RESERVED => 'lock-closed',
            self::TYPE_CANCELLED => 'x-circle',
        ];

        return $icons[$this->type] ?? 'document';
    }

    /**
     * Check if movement is positive (adds stock).
     */
    public function isPositive(): bool
    {
        return in_array($this->type, [self::TYPE_IN, self::TYPE_RETURN, self::TYPE_CANCELLED])
            || ($this->type === self::TYPE_ADJUSTMENT && $this->quantity > 0);
    }

    /**
     * Check if movement is negative (removes stock).
     */
    public function isNegative(): bool
    {
        return in_array($this->type, [self::TYPE_OUT, self::TYPE_RESERVED])
            || ($this->type === self::TYPE_ADJUSTMENT && $this->quantity < 0);
    }

    /**
     * Get formatted quantity with sign.
     */
    public function getFormattedQuantityAttribute(): string
    {
        $sign = $this->quantity >= 0 ? '+' : '';
        return $sign . $this->quantity;
    }

    /**
     * Get total value (quantity * unit_cost).
     */
    public function getTotalValueAttribute(): ?float
    {
        if ($this->unit_cost) {
            return abs($this->quantity) * $this->unit_cost;
        }
        return null;
    }

    /**
     * Get formatted total value.
     */
    public function getFormattedTotalValueAttribute(): ?string
    {
        if ($this->total_value) {
            return number_format($this->total_value, 2, ',', '.') . ' ₺';
        }
        return null;
    }
}
