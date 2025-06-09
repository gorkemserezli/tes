<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class CustomerGroup extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'discount_percentage',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'discount_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the users that belong to the group.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_groups', 'group_id', 'user_id');
    }

    /**
     * Get the custom prices for the group.
     */
    public function customPrices()
    {
        return $this->hasMany(CustomPrice::class, 'group_id');
    }

    /**
     * Scope for active groups.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Add user to group.
     */
    public function addUser(User $user)
    {
        return $this->users()->attach($user);
    }

    /**
     * Remove user from group.
     */
    public function removeUser(User $user)
    {
        return $this->users()->detach($user);
    }

    /**
     * Check if user is in group.
     */
    public function hasUser(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Get user count.
     */
    public function getUserCountAttribute(): int
    {
        return $this->users()->count();
    }

    /**
     * Get active user count.
     */
    public function getActiveUserCountAttribute(): int
    {
        return $this->users()->active()->count();
    }

    /**
     * Get custom price count.
     */
    public function getCustomPriceCountAttribute(): int
    {
        return $this->customPrices()->count();
    }

    /**
     * Toggle active status.
     */
    public function toggleActive()
    {
        $this->update(['is_active' => !$this->is_active]);
    }

    /**
     * Get formatted discount.
     */
    public function getFormattedDiscountAttribute(): string
    {
        return '%' . number_format($this->discount_percentage, 2, ',', '.');
    }
}
