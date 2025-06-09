<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemLog extends Model
{
    use HasFactory;

    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_NOTICE = 'notice';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    const LEVEL_ALERT = 'alert';
    const LEVEL_EMERGENCY = 'emergency';

    /**
     * Log channels
     */
    const CHANNEL_APPLICATION = 'application';
    const CHANNEL_AUTH = 'auth';
    const CHANNEL_PAYMENT = 'payment';
    const CHANNEL_ORDER = 'order';
    const CHANNEL_STOCK = 'stock';
    const CHANNEL_SYSTEM = 'system';
    const CHANNEL_API = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'level',
        'channel',
        'message',
        'context',
        'user_id',
        'ip_address',
        'user_agent',
        'url',
        'method',
        'exception_class',
        'exception_file',
        'exception_line',
        'exception_trace',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'context' => 'array',
    ];

    /**
     * Get the user that caused the log.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for level
     */
    public function scopeLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope for channel
     */
    public function scopeChannel($query, $channel)
    {
        return $query->where('channel', $channel);
    }

    /**
     * Scope for error and above
     */
    public function scopeErrors($query)
    {
        return $query->whereIn('level', [
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_ALERT,
            self::LEVEL_EMERGENCY
        ]);
    }

    /**
     * Scope for date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get level color for UI
     */
    public function getLevelColorAttribute(): string
    {
        $colors = [
            self::LEVEL_DEBUG => 'gray',
            self::LEVEL_INFO => 'blue',
            self::LEVEL_NOTICE => 'indigo',
            self::LEVEL_WARNING => 'yellow',
            self::LEVEL_ERROR => 'red',
            self::LEVEL_CRITICAL => 'red',
            self::LEVEL_ALERT => 'purple',
            self::LEVEL_EMERGENCY => 'red',
        ];

        return $colors[$this->level] ?? 'gray';
    }

    /**
     * Get level icon for UI
     */
    public function getLevelIconAttribute(): string
    {
        $icons = [
            self::LEVEL_DEBUG => 'bug',
            self::LEVEL_INFO => 'information-circle',
            self::LEVEL_NOTICE => 'flag',
            self::LEVEL_WARNING => 'exclamation',
            self::LEVEL_ERROR => 'x-circle',
            self::LEVEL_CRITICAL => 'fire',
            self::LEVEL_ALERT => 'bell',
            self::LEVEL_EMERGENCY => 'lightning-bolt',
        ];

        return $icons[$this->level] ?? 'document-text';
    }

    /**
     * Check if log is error level or above
     */
    public function isError(): bool
    {
        return in_array($this->level, [
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_ALERT,
            self::LEVEL_EMERGENCY
        ]);
    }

    /**
     * Check if log has exception
     */
    public function hasException(): bool
    {
        return !empty($this->exception_class);
    }

    /**
     * Get formatted exception
     */
    public function getFormattedExceptionAttribute(): ?string
    {
        if (!$this->hasException()) {
            return null;
        }

        return sprintf(
            '%s in %s:%d',
            $this->exception_class,
            basename($this->exception_file),
            $this->exception_line
        );
    }

    /**
     * Get channel label
     */
    public function getChannelLabelAttribute(): string
    {
        $labels = [
            self::CHANNEL_APPLICATION => 'Uygulama',
            self::CHANNEL_AUTH => 'Kimlik Doğrulama',
            self::CHANNEL_PAYMENT => 'Ödeme',
            self::CHANNEL_ORDER => 'Sipariş',
            self::CHANNEL_STOCK => 'Stok',
            self::CHANNEL_SYSTEM => 'Sistem',
            self::CHANNEL_API => 'API',
        ];

        return $labels[$this->channel] ?? $this->channel;
    }
}
