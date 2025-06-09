<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Traits\LogsActivity;

class Category extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image',
        'sort_order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('name') && !$category->isDirty('slug')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Get the parent category.
     */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the child categories.
     */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Get all descendants (recursive).
     */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /**
     * Get the products for the category.
     */
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all products including descendants' products.
     */
    public function allProducts()
    {
        $categoryIds = $this->descendants->pluck('id')->push($this->id);
        return Product::whereIn('category_id', $categoryIds);
    }

    /**
     * Scope for active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for root categories.
     */
    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Get breadcrumb path.
     */
    public function getBreadcrumbsAttribute()
    {
        $breadcrumbs = collect([$this]);
        $parent = $this->parent;

        while ($parent) {
            $breadcrumbs->prepend($parent);
            $parent = $parent->parent;
        }

        return $breadcrumbs;
    }

    /**
     * Get full path (for URLs).
     */
    public function getFullPathAttribute()
    {
        return $this->breadcrumbs->pluck('slug')->implode('/');
    }

    /**
     * Check if category has children.
     */
    public function hasChildren(): bool
    {
        return $this->children()->exists();
    }

    /**
     * Check if category has products.
     */
    public function hasProducts(): bool
    {
        return $this->products()->exists();
    }

    /**
     * Check if category can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return !$this->hasChildren() && !$this->hasProducts();
    }

    /**
     * Get category tree (static method).
     */
    public static function getTree($parentId = null, $onlyActive = true)
    {
        $query = static::with('children')
            ->where('parent_id', $parentId)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($onlyActive) {
            $query->active();
        }

        return $query->get();
    }

    /**
     * Get flattened tree with depth.
     */
    public static function getFlatTree($parentId = null, $prefix = '', $onlyActive = true)
    {
        $categories = collect();
        $roots = static::getTree($parentId, $onlyActive);

        foreach ($roots as $root) {
            $categories->push([
                'id' => $root->id,
                'name' => $prefix . $root->name,
                'original_name' => $root->name,
                'slug' => $root->slug,
                'depth' => substr_count($prefix, '—'),
            ]);

            if ($root->children->count() > 0) {
                $children = static::getFlatTree($root->id, $prefix . '— ', $onlyActive);
                $categories = $categories->merge($children);
            }
        }

        return $categories;
    }

    /**
     * Get image URL.
     */
    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return asset('storage/' . $this->image);
        }
        return null;
    }
}
