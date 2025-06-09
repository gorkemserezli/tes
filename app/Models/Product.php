<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\LogsActivity;

class Product extends Model implements Auditable
{
    use HasFactory, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'slug',
        'description',
        'short_description',
        'base_price',
        'vat_rate',
        'currency',
        'stock_quantity',
        'min_order_quantity',
        'weight',
        'is_active',
        'is_featured',
        'view_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'base_price' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'weight' => 'decimal:3',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'stock_quantity' => 'integer',
        'min_order_quantity' => 'integer',
        'view_count' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['price_with_vat', 'in_stock'];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name);
            }
        });

        static::updating(function ($product) {
            if ($product->isDirty('name') && !$product->isDirty('slug')) {
                $product->slug = Str::slug($product->name);
            }
        });
    }

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the images for the product.
     */
    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    /**
     * Get the primary image.
     */
    public function primaryImage()
    {
        return $this->hasOne(ProductImage::class)->where('is_primary', true);
    }

    /**
     * Get the custom prices for the product.
     */
    public function customPrices()
    {
        return $this->hasMany(CustomPrice::class);
    }

    /**
     * Get the stock movements for the product.
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get the order items for the product.
     */
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the cart items for the product.
     */
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Scope for active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for featured products.
     */
    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    /**
     * Scope for in-stock products.
     */
    public function scopeInStock($query)
    {
        return $query->where('stock_quantity', '>', 0);
    }

    /**
     * Scope for search.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    /**
     * Get price with VAT.
     */
    public function getPriceWithVatAttribute()
    {
        return $this->base_price * (1 + $this->vat_rate / 100);
    }

    /**
     * Get VAT amount.
     */
    public function getVatAmountAttribute()
    {
        return $this->base_price * ($this->vat_rate / 100);
    }

    /**
     * Check if product is in stock.
     */
    public function getInStockAttribute()
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Check if product has enough stock.
     */
    public function hasStock($quantity = 1): bool
    {
        return $this->stock_quantity >= $quantity;
    }

    /**
     * Get price for user/group.
     */
    public function getPriceForUser(User $user, $quantity = 1)
    {
        // First check user-specific price
        $userPrice = $this->customPrices()
            ->where('user_id', $user->id)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->orderBy('min_quantity', 'desc')
            ->first();

        if ($userPrice) {
            return $userPrice->price;
        }

        // Then check group prices
        $groupIds = $user->groups()->pluck('id');

        $groupPrice = $this->customPrices()
            ->whereIn('group_id', $groupIds)
            ->where('min_quantity', '<=', $quantity)
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->orderBy('price') // Get best price
            ->orderBy('min_quantity', 'desc')
            ->first();

        if ($groupPrice) {
            return $groupPrice->price;
        }

        // Apply group discount if any
        $discountPercentage = $user->getDiscountPercentage();
        if ($discountPercentage > 0) {
            return $this->base_price * (1 - $discountPercentage / 100);
        }

        // Return base price
        return $this->base_price;
    }

    /**
     * Add stock.
     */
    public function addStock($quantity, $description = null, $unitCost = null, User $createdBy = null)
    {
        $this->increment('stock_quantity', $quantity);

        StockMovement::create([
            'product_id' => $this->id,
            'type' => 'in',
            'quantity' => $quantity,
            'stock_before' => $this->stock_quantity - $quantity,
            'stock_after' => $this->stock_quantity,
            'description' => $description ?? 'Stok girişi',
            'unit_cost' => $unitCost,
            'created_by' => $createdBy?->id,
        ]);
    }

    /**
     * Reduce stock.
     */
    public function reduceStock($quantity, $referenceType = null, $referenceId = null, $description = null)
    {
        if (!$this->hasStock($quantity)) {
            throw new \Exception('Yetersiz stok');
        }

        $this->decrement('stock_quantity', $quantity);

        StockMovement::create([
            'product_id' => $this->id,
            'type' => 'out',
            'quantity' => -$quantity,
            'stock_before' => $this->stock_quantity + $quantity,
            'stock_after' => $this->stock_quantity,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'description' => $description ?? 'Stok çıkışı',
        ]);
    }

    /**
     * Adjust stock.
     */
    public function adjustStock($newQuantity, $reason, User $adjustedBy)
    {
        $oldQuantity = $this->stock_quantity;
        $difference = $newQuantity - $oldQuantity;

        $this->update(['stock_quantity' => $newQuantity]);

        StockMovement::create([
            'product_id' => $this->id,
            'type' => 'adjustment',
            'quantity' => $difference,
            'stock_before' => $oldQuantity,
            'stock_after' => $newQuantity,
            'description' => 'Stok düzeltmesi: ' . $reason,
            'created_by' => $adjustedBy->id,
        ]);
    }

    /**
     * Reserve stock (for pending orders).
     */
    public function reserveStock($quantity, $orderId)
    {
        if (!$this->hasStock($quantity)) {
            throw new \Exception('Yetersiz stok');
        }

        $this->decrement('stock_quantity', $quantity);

        StockMovement::create([
            'product_id' => $this->id,
            'type' => 'reserved',
            'quantity' => -$quantity,
            'stock_before' => $this->stock_quantity + $quantity,
            'stock_after' => $this->stock_quantity,
            'reference_type' => 'orders',
            'reference_id' => $orderId,
            'description' => 'Sipariş için rezerve edildi',
        ]);
    }

    /**
     * Release reserved stock (for cancelled orders).
     */
    public function releaseStock($quantity, $orderId)
    {
        $this->increment('stock_quantity', $quantity);

        StockMovement::create([
            'product_id' => $this->id,
            'type' => 'cancelled',
            'quantity' => $quantity,
            'stock_before' => $this->stock_quantity - $quantity,
            'stock_after' => $this->stock_quantity,
            'reference_type' => 'orders',
            'reference_id' => $orderId,
            'description' => 'Rezerve edilen stok serbest bırakıldı',
        ]);
    }

    /**
     * Increment view count.
     */
    public function incrementViewCount()
    {
        $this->increment('view_count');
    }

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute()
    {
        return number_format($this->base_price, 2, ',', '.') . ' ' . $this->currency;
    }

    /**
     * Get formatted price with VAT.
     */
    public function getFormattedPriceWithVatAttribute()
    {
        return number_format($this->price_with_vat, 2, ',', '.') . ' ' . $this->currency;
    }
}
