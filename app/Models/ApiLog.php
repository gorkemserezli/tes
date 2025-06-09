<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'method',
        'endpoint',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'response_time',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_headers' => 'array',
        'request_body' => 'array',
        'response_body' => 'array',
        'response_time' => 'float',
        'response_status' => 'integer',
    ];

    /**
     * Get the user that made the request.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope for user logs.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for endpoint.
     */
    public function scopeForEndpoint($query, $endpoint)
    {
        return $query->where('endpoint', 'like', "%{$endpoint}%");
    }

    /**
     * Scope for status.
     */
    public function scopeStatus($query, $status)
    {
        return $query->where('response_status', $status);
    }

    /**
     * Scope for errors.
     */
    public function scopeErrors($query)
    {
        return $query->where('response_status', '>=', 400);
    }
}
