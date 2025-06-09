<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

trait LogsActivity
{
    /**
     * Boot the trait
     */
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->logActivity('created', 'Created ' . class_basename($model));
        });

        static::updated(function ($model) {
            if (!$model->wasRecentlyCreated) {
                $changes = $model->getChanges();
                unset($changes['updated_at']);

                if (!empty($changes)) {
                    $model->logActivity('updated', 'Updated ' . class_basename($model), $changes);
                }
            }
        });

        static::deleted(function ($model) {
            $model->logActivity('deleted', 'Deleted ' . class_basename($model));
        });
    }

    /**
     * Log activity
     */
    public function logActivity(string $type, string $description, array $properties = [])
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'log_type' => $type,
            'subject_type' => get_class($this),
            'subject_id' => $this->id,
            'description' => $description,
            'properties' => !empty($properties) ? $properties : null,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Get activity logs
     */
    public function activityLogs()
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }
}
