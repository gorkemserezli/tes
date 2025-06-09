<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class CustomPrice extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'user_id',
        'group_id',
        'price',
        'min_quantity',
        'start_date',
        'end_date',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'min_quantity' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Get the product that owns the custom price.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user that owns the custom price.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer group that owns the custom price.
     */
    public function group()
    {
        return $this->belongsTo(CustomerGroup::class, 'group_id');
    }

    /**
     * Scope for active prices (within date range).
     */
    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('start_date')
                ->orWhere('start_date', '<=', now());
        })->where(function ($q) {
            $q->whereNull('end_date')
                ->orWhere('end_date', '>=', now());
        });
    }

    /**
     * Scope for user prices.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for group prices.
     */
    public function scopeForGroup($query, $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * Check if price is active.
     */
    public function isActive(): bool
    {
        $now = now();

        if ($this->start_date && $this->start_date > $now) {
            return false;
        }

        if ($this->end_date && $this->end_date < $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if price applies to quantity.
     */
    public function appliesTo($quantity): bool
    {
        return $quantity >= $this->min_quantity;
    }

    /**
     * Get price type (user or group).
     */
    public function getTypeAttribute(): string
    {
        return $this->user_id ? 'user' : 'group';
    }

    /**
     * Get price target name.
     */
    public function getTargetNameAttribute(): string
    {
        if ($this->user_id) {
            return $this->user->name;
        }

        return $this->group->name;
    }

    /**
     * Get discount percentage from base price.
     */
    public function getDiscountPercentageAttribute(): float
    {
        $basePrice = $this->product->base_price;

        if ($basePrice <= 0) {
            return 0;
        }

        return round((($basePrice - $this->price) / $basePrice) * 100, 2);
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 2, ',', '.') . ' ₺';
    }

    /**
     * Get status label.
     */
    public function getStatusLabelAttribute(): string
    {
        if (!$this->start_date && !$this->end_date) {
            return 'Süresiz';
        }

        if ($this->isActive()) {
            return 'Aktif';
        }

        if ($this->start_date && $this->start_date > now()) {
            return 'Beklemede';
        }

        return 'Süresi Dolmuş';
    }

    /**
     * Get status color.
     */
    public function getStatusColorAttribute(): string
    {
        $status = $this->status_label;

        return match($status) {
            'Aktif', 'Süresiz' => 'green',
            'Beklemede' => 'yellow',
            'Süresi Dolmuş' => 'red',
            default => 'gray'
        };
    }
}
