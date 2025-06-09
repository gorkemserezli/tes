<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'metric_type',
        'metric_value',
        'endpoint',
        'method',
        'user_id',
        'ip_address',
        'user_agent',
        'memory_usage',
        'cpu_usage',
        'response_time',
        'status_code',
        'metadata'
    ];

    protected $casts = [
        'metric_value' => 'float',
        'memory_usage' => 'integer',
        'cpu_usage' => 'float',
        'response_time' => 'float',
        'status_code' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * İlişkili kullanıcı
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Belirli bir metrik tipine göre filtrele
     */
    public function scopeByMetricType($query, $type)
    {
        return $query->where('metric_type', $type);
    }

    /**
     * Scope: Belirli bir endpoint'e göre filtrele
     */
    public function scopeByEndpoint($query, $endpoint)
    {
        return $query->where('endpoint', $endpoint);
    }

    /**
     * Scope: Belirli bir zaman aralığında
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope: Yavaş istekler (örn: 1 saniyeden uzun)
     */
    public function scopeSlowRequests($query, $threshold = 1000)
    {
        return $query->where('response_time', '>', $threshold);
    }

    /**
     * Scope: Başarısız istekler
     */
    public function scopeFailedRequests($query)
    {
        return $query->where('status_code', '>=', 400);
    }

    /**
     * Ortalama response time hesapla
     */
    public static function averageResponseTime($endpoint = null, $period = 'today')
    {
        $query = self::query();

        if ($endpoint) {
            $query->where('endpoint', $endpoint);
        }

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->where('created_at', '>=', now()->subWeek());
                break;
            case 'month':
                $query->where('created_at', '>=', now()->subMonth());
                break;
        }

        return $query->avg('response_time');
    }

    /**
     * En çok kullanılan endpoint'leri getir
     */
    public static function topEndpoints($limit = 10, $period = 'today')
    {
        $query = self::query()
            ->selectRaw('endpoint, COUNT(*) as count, AVG(response_time) as avg_response_time')
            ->groupBy('endpoint')
            ->orderByDesc('count')
            ->limit($limit);

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->where('created_at', '>=', now()->subWeek());
                break;
            case 'month':
                $query->where('created_at', '>=', now()->subMonth());
                break;
        }

        return $query->get();
    }

    /**
     * Performans istatistiklerini getir
     */
    public static function getStatistics($period = 'today')
    {
        $query = self::query();

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->where('created_at', '>=', now()->subWeek());
                break;
            case 'month':
                $query->where('created_at', '>=', now()->subMonth());
                break;
        }

        return [
            'total_requests' => $query->count(),
            'avg_response_time' => $query->avg('response_time'),
            'max_response_time' => $query->max('response_time'),
            'min_response_time' => $query->min('response_time'),
            'avg_memory_usage' => $query->avg('memory_usage'),
            'failed_requests' => $query->where('status_code', '>=', 400)->count(),
            'success_rate' => $query->count() > 0
                ? (($query->count() - $query->where('status_code', '>=', 400)->count()) / $query->count()) * 100
                : 0,
        ];
    }
}
