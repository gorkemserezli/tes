<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'unit_price',
        'vat_rate',
        'discount_amount',
        'total_price',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['subtotal', 'vat_amount'];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            // Calculate total price if not set
            if (!$item->total_price) {
                $item->calculateTotalPrice();
            }
        });

        static::updating(function ($item) {
            // Recalculate total price if quantity or price changes
            if ($item->isDirty(['quantity', 'unit_price', 'discount_amount', 'vat_rate'])) {
                $item->calculateTotalPrice();
            }
        });
    }

    /**
     * Get the order that owns the item.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate total price.
     */
    public function calculateTotalPrice(): void
    {
        $subtotal = ($this->unit_price * $this->quantity) - $this->discount_amount;
        $vatAmount = $subtotal * ($this->vat_rate / 100);

        $this->total_price = $subtotal + $vatAmount;
    }

    /**
     * Get subtotal (without VAT).
     */
    public function getSubtotalAttribute(): float
    {
        return ($this->unit_price * $this->quantity) - $this->discount_amount;
    }

    /**
     * Get VAT amount.
     */
    public function getVatAmountAttribute(): float
    {
        return $this->subtotal * ($this->vat_rate / 100);
    }

    /**
     * Get price without VAT.
     */
    public function getPriceWithoutVatAttribute(): float
    {
        return $this->subtotal;
    }

    /**
     * Get unit price with VAT.
     */
    public function getUnitPriceWithVatAttribute(): float
    {
        return $this->unit_price * (1 + $this->vat_rate / 100);
    }

    /**
     * Get formatted unit price.
     */
    public function getFormattedUnitPriceAttribute(): string
    {
        return number_format($this->unit_price, 2, ',', '.') . ' ₺';
    }

    /**
     * Get formatted total price.
     */
    public function getFormattedTotalPriceAttribute(): string
    {
        return number_format($this->total_price, 2, ',', '.') . ' ₺';
    }

    /**
     * Get discount percentage.
     */
    public function getDiscountPercentageAttribute(): float
    {
        $originalPrice = $this->unit_price * $this->quantity;

        if ($originalPrice <= 0) {
            return 0;
        }

        return round(($this->discount_amount / $originalPrice) * 100, 2);
    }

    /**
     * Update from cart item.
     */
    public static function createFromCartItem(CartItem $cartItem, Order $order, float $unitPrice): self
    {
        $product = $cartItem->product;

        return static::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $cartItem->quantity,
            'unit_price' => $unitPrice,
            'vat_rate' => $product->vat_rate,
            'discount_amount' => 0, // Can be calculated based on campaigns
        ]);
    }
}
