<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use OwenIt\Auditing\Contracts\Auditable;
use App\Traits\LogsActivity;

class User extends Authenticatable implements MustVerifyEmail, Auditable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;
    use \OwenIt\Auditing\Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'is_active',
        'is_admin',
        'email_verified_at',
        'two_factor_code',
        'two_factor_expires_at',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'two_factor_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'is_admin' => 'boolean',
    ];

    /**
     * Attributes to include in the Audit.
     *
     * @var array
     */
    protected $auditInclude = [
        'name',
        'email',
        'phone',
        'is_active',
        'is_admin',
    ];

    /**
     * Get the company associated with the user.
     */
    public function company()
    {
        return $this->hasOne(Company::class);
    }

    /**
     * Get the customer groups for the user.
     */
    public function groups()
    {
        return $this->belongsToMany(CustomerGroup::class, 'user_groups', 'user_id', 'group_id');
    }

    /**
     * Get the orders for the user.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the cart items for the user.
     */
    public function cartItems()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Get the balance transactions for the user.
     */
    public function balanceTransactions()
    {
        return $this->hasMany(BalanceTransaction::class);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get the custom prices for the user.
     */
    public function customPrices()
    {
        return $this->hasMany(CustomPrice::class);
    }

    /**
     * Check if user is active
     */
    public function isActive(): bool
    {
        return $this->is_active && $this->email_verified_at !== null;
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    /**
     * Check if user is customer
     */
    public function isCustomer(): bool
    {
        return !$this->is_admin;
    }

    /**
     * Check if user's company is approved
     */
    public function isApproved(): bool
    {
        return $this->company && $this->company->is_approved;
    }

    /**
     * Generate two factor code
     */
    public function generateTwoFactorCode(): string
    {
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'two_factor_code' => $code,
            'two_factor_expires_at' => now()->addMinutes(config('auth.two_factor_expire_minutes', 10))
        ]);

        return $code;
    }

    /**
     * Verify two factor code
     */
    public function verifyTwoFactorCode(string $code): bool
    {
        return $this->two_factor_code === $code
            && $this->two_factor_expires_at
            && $this->two_factor_expires_at->isFuture();
    }

    /**
     * Clear two factor code
     */
    public function clearTwoFactorCode(): void
    {
        $this->update([
            'two_factor_code' => null,
            'two_factor_expires_at' => null
        ]);
    }

    /**
     * Get user's current balance
     */
    public function getBalance(): float
    {
        return $this->company ? $this->company->balance : 0;
    }

    /**
     * Get user's credit limit
     */
    public function getCreditLimit(): float
    {
        return $this->company ? $this->company->credit_limit : 0;
    }

    /**
     * Get user's remaining credit
     */
    public function getRemainingCredit(): float
    {
        return $this->company ? $this->company->remaining_credit : 0;
    }

    /**
     * Check if user can make purchase with given amount
     */
    public function canPurchase(float $amount, string $paymentMethod): bool
    {
        if (!$this->isActive() || !$this->isApproved()) {
            return false;
        }

        if ($paymentMethod === 'balance') {
            return $this->getBalance() >= $amount;
        }

        return true;
    }

    /**
     * Update last login
     */
    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    /**
     * Scope active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereNotNull('email_verified_at');
    }

    /**
     * Scope admin users
     */
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true);
    }

    /**
     * Scope customer users
     */
    public function scopeCustomers($query)
    {
        return $query->where('is_admin', false);
    }

    /**
     * Get user's discount percentage based on groups
     */
    public function getDiscountPercentage(): float
    {
        return $this->groups()
            ->where('is_active', true)
            ->max('discount_percentage') ?? 0;
    }
}
