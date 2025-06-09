<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'product_id',
        'image_path',
        'alt_text',
        'sort_order',
        'is_primary',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['url', 'thumbnail_url'];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        // When setting a new primary image, unset others
        static::saving(function ($image) {
            if ($image->is_primary) {
                static::where('product_id', $image->product_id)
                    ->where('id', '!=', $image->id)
                    ->update(['is_primary' => false]);
            }
        });

        // Delete physical file when model is deleted
        static::deleting(function ($image) {
            if (Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
            }

            // Delete thumbnail if exists
            $thumbnailPath = str_replace('products/', 'products/thumbnails/', $image->image_path);
            if (Storage::disk('public')->exists($thumbnailPath)) {
                Storage::disk('public')->delete($thumbnailPath);
            }
        });
    }

    /**
     * Get the product that owns the image.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the full URL of the image.
     */
    public function getUrlAttribute()
    {
        return asset('storage/' . $this->image_path);
    }

    /**
     * Get the thumbnail URL.
     */
    public function getThumbnailUrlAttribute()
    {
        $thumbnailPath = str_replace('products/', 'products/thumbnails/', $this->image_path);

        if (Storage::disk('public')->exists($thumbnailPath)) {
            return asset('storage/' . $thumbnailPath);
        }

        // Return original if thumbnail doesn't exist
        return $this->url;
    }

    /**
     * Set as primary image.
     */
    public function setAsPrimary()
    {
        $this->update(['is_primary' => true]);
    }

    /**
     * Get dimensions of the image.
     */
    public function getDimensions()
    {
        $path = Storage::disk('public')->path($this->image_path);

        if (file_exists($path)) {
            $imageInfo = getimagesize($path);
            return [
                'width' => $imageInfo[0] ?? null,
                'height' => $imageInfo[1] ?? null,
            ];
        }

        return ['width' => null, 'height' => null];
    }

    /**
     * Get file size in bytes.
     */
    public function getFileSize()
    {
        if (Storage::disk('public')->exists($this->image_path)) {
            return Storage::disk('public')->size($this->image_path);
        }

        return 0;
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSize()
    {
        $bytes = $this->getFileSize();

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }
}
