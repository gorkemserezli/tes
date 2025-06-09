<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'product_id',
        'quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['total_price', 'total_price_with_vat'];

    /**
     * Get the user that owns the cart item.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get unit price for the user.
     */
    public function getUnitPriceAttribute(): float
    {
        return $this->product->getPriceForUser($this->user, $this->quantity);
    }

    /**
     * Get total price (without VAT).
     */
    public function getTotalPriceAttribute(): float
    {
        return $this->unit_price * $this->quantity;
    }

    /**
     * Get total VAT amount.
     */
    public function getTotalVatAttribute(): float
    {
        return $this->total_price * ($this->product->vat_rate / 100);
    }

    /**
     * Get total price with VAT.
     */
    public function getTotalPriceWithVatAttribute(): float
    {
        return $this->total_price + $this->total_vat;
    }

    /**
     * Update quantity.
     */
    public function updateQuantity(int $quantity): bool
    {
        if ($quantity <= 0) {
            return $this->delete();
        }

        // Check stock
        if (!$this->product->hasStock($quantity)) {
            throw new \Exception('Yetersiz stok. Mevcut stok: ' . $this->product->stock_quantity);
        }

        // Check minimum order quantity
        if ($quantity < $this->product->min_order_quantity) {
            throw new \Exception('Minimum sipariş miktarı: ' . $this->product->min_order_quantity);
        }

        return $this->update(['quantity' => $quantity]);
    }

    /**
     * Increment quantity.
     */
    public function incrementQuantity(int $amount = 1): bool
    {
        return $this->updateQuantity($this->quantity + $amount);
    }

    /**
     * Decrement quantity.
     */
    public function decrementQuantity(int $amount = 1): bool
    {
        return $this->updateQuantity($this->quantity - $amount);
    }

    /**
     * Check if product is available.
     */
    public function isAvailable(): bool
    {
        return $this->product->is_active
            && $this->product->hasStock($this->quantity);
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
     * Get formatted total price with VAT.
     */
    public function getFormattedTotalPriceWithVatAttribute(): string
    {
        return number_format($this->total_price_with_vat, 2, ',', '.') . ' ₺';
    }

    /**
     * Create or update cart item.
     */
    public static function addItem(User $user, Product $product, int $quantity): self
    {
        // Check if product is active
        if (!$product->is_active) {
            throw new \Exception('Bu ürün satışta değil.');
        }

        // Check stock
        if (!$product->hasStock($quantity)) {
            throw new \Exception('Yetersiz stok. Mevcut stok: ' . $product->stock_quantity);
        }

        // Check minimum order quantity
        if ($quantity < $product->min_order_quantity) {
            throw new \Exception('Minimum sipariş miktarı: ' . $product->min_order_quantity);
        }

        // Find existing cart item
        $cartItem = static::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($cartItem) {
            // Update quantity
            $newQuantity = $cartItem->quantity + $quantity;

            // Check stock for new quantity
            if (!$product->hasStock($newQuantity)) {
                throw new \Exception('Yetersiz stok. Mevcut stok: ' . $product->stock_quantity);
            }

            $cartItem->update(['quantity' => $newQuantity]);
        } else {
            // Create new cart item
            $cartItem = static::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]);
        }

        return $cartItem;
    }

    /**
     * Get cart summary for user.
     */
    public static function getSummary(User $user): array
    {
        $items = static::with('product')
            ->where('user_id', $user->id)
            ->get();

        $subtotal = 0;
        $vatTotal = 0;
        $itemCount = 0;
        $unavailableCount = 0;

        foreach ($items as $item) {
            if ($item->isAvailable()) {
                $subtotal += $item->total_price;
                $vatTotal += $item->total_vat;
                $itemCount += $item->quantity;
            } else {
                $unavailableCount++;
            }
        }

        $grandTotal = $subtotal + $vatTotal;

        return [
            'item_count' => $itemCount,
            'product_count' => $items->count(),
            'unavailable_count' => $unavailableCount,
            'subtotal' => $subtotal,
            'vat_total' => $vatTotal,
            'grand_total' => $grandTotal,
            'formatted_subtotal' => number_format($subtotal, 2, ',', '.') . ' ₺',
            'formatted_vat_total' => number_format($vatTotal, 2, ',', '.') . ' ₺',
            'formatted_grand_total' => number_format($grandTotal, 2, ',', '.') . ' ₺',
        ];
    }

    /**
     * Clear user's cart.
     */
    public static function clearCart(User $user): bool
    {
        return static::where('user_id', $user->id)->delete();
    }
}
