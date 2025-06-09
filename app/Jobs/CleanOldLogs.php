<?php

namespace App\Jobs;

use App\Models\SystemLog;
use App\Models\ActivityLog;
use App\Models\ApiLog;
use App\Models\PerformanceLog;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CleanOldLogs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $daysToKeep;

    /**
     * Create a new job instance.
     */
    public function __construct(int $daysToKeep = 30)
    {
        $this->daysToKeep = $daysToKeep;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cutoffDate = Carbon::now()->subDays($this->daysToKeep);

        // Clean system logs
        $systemLogsDeleted = SystemLog::where('created_at', '<', $cutoffDate)
            ->whereNotIn('level', ['error', 'critical', 'alert', 'emergency']) // Keep error logs longer
            ->delete();

        // Clean activity logs
        $activityLogsDeleted = ActivityLog::where('created_at', '<', $cutoffDate)
            ->delete();

        // Clean API logs
        $apiLogsDeleted = ApiLog::where('created_at', '<', $cutoffDate)
            ->delete();

        // Clean performance logs
        $performanceLogsDeleted = PerformanceLog::where('created_at', '<', $cutoffDate)
            ->delete();

        // Keep error logs for 90 days
        $errorCutoffDate = Carbon::now()->subDays(90);
        $errorLogsDeleted = SystemLog::where('created_at', '<', $errorCutoffDate)
            ->whereIn('level', ['error', 'critical', 'alert', 'emergency'])
            ->delete();

        Log::info('Old logs cleaned', [
            'cutoff_date' => $cutoffDate->toDateString(),
            'system_logs_deleted' => $systemLogsDeleted,
            'activity_logs_deleted' => $activityLogsDeleted,
            'api_logs_deleted' => $apiLogsDeleted,
            'performance_logs_deleted' => $performanceLogsDeleted,
            'error_logs_deleted' => $errorLogsDeleted,
        ]);
    }
}
