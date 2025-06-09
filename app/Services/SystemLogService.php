<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;

class SystemLogService
{
    /**
     * Log levels
     */
    const LEVELS = [
        'debug' => 'debug',
        'info' => 'info',
        'notice' => 'notice',
        'warning' => 'warning',
        'error' => 'error',
        'critical' => 'critical',
        'alert' => 'alert',
        'emergency' => 'emergency'
    ];

    /**
     * Log a message
     */
    public function log(string $level, string $channel, string $message, array $context = [], ?\Exception $exception = null)
    {
        try {
            $logData = [
                'level' => $level,
                'channel' => $channel,
                'message' => $message,
                'context' => !empty($context) ? $context : null,
                'user_id' => Auth::id(),
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'url' => Request::fullUrl(),
                'method' => Request::method(),
            ];

            // Add exception details if provided
            if ($exception) {
                $logData['exception_class'] = get_class($exception);
                $logData['exception_file'] = $exception->getFile();
                $logData['exception_line'] = $exception->getLine();
                $logData['exception_trace'] = $exception->getTraceAsString();

                // Add exception details to context
                $logData['context'] = array_merge($context ?? [], [
                    'exception_message' => $exception->getMessage(),
                    'exception_code' => $exception->getCode()
                ]);
            }

            // Save to database
            SystemLog::create($logData);

            // Also log to Laravel's log system for important levels
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                Log::channel($channel)->$level($message, $context);
            }

        } catch (\Exception $e) {
            // If database logging fails, fall back to file logging
            Log::channel('system')->error('Failed to log to database: ' . $e->getMessage(), [
                'original_message' => $message,
                'original_context' => $context
            ]);
        }
    }

    /**
     * Log debug message
     */
    public function debug(string $channel, string $message, array $context = [])
    {
        $this->log('debug', $channel, $message, $context);
    }

    /**
     * Log info message
     */
    public function info(string $channel, string $message, array $context = [])
    {
        $this->log('info', $channel, $message, $context);
    }

    /**
     * Log notice message
     */
    public function notice(string $channel, string $message, array $context = [])
    {
        $this->log('notice', $channel, $message, $context);
    }

    /**
     * Log warning message
     */
    public function warning(string $channel, string $message, array $context = [])
    {
        $this->log('warning', $channel, $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $channel, string $message, array $context = [], ?\Exception $exception = null)
    {
        $this->log('error', $channel, $message, $context, $exception);
    }

    /**
     * Log critical message
     */
    public function critical(string $channel, string $message, array $context = [], ?\Exception $exception = null)
    {
        $this->log('critical', $channel, $message, $context, $exception);
    }

    /**
     * Log alert message
     */
    public function alert(string $channel, string $message, array $context = [])
    {
        $this->log('alert', $channel, $message, $context);
    }

    /**
     * Log emergency message
     */
    public function emergency(string $channel, string $message, array $context = [])
    {
        $this->log('emergency', $channel, $message, $context);
    }

    /**
     * Log performance metrics
     */
    public function logPerformance(string $type, string $action, float $duration, ?int $memoryUsage = null, array $context = [])
    {
        try {
            \App\Models\PerformanceLog::create([
                'type' => $type,
                'action' => $action,
                'duration' => $duration,
                'memory_usage' => $memoryUsage,
                'context' => !empty($context) ? $context : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log performance metrics: ' . $e->getMessage());
        }
    }

    /**
     * Log API request/response
     */
    public function logApiRequest(
        string $method,
        string $endpoint,
        ?array $requestHeaders,
               $requestBody,
        int $responseStatus,
               $responseBody,
        float $responseTime
    ) {
        try {
            \App\Models\ApiLog::create([
                'user_id' => Auth::id(),
                'method' => $method,
                'endpoint' => $endpoint,
                'request_headers' => $requestHeaders,
                'request_body' => is_array($requestBody) ? $requestBody : null,
                'response_status' => $responseStatus,
                'response_body' => is_array($responseBody) ? $responseBody : null,
                'response_time' => $responseTime,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log API request: ' . $e->getMessage());
        }
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $daysToKeep = 30)
    {
        try {
            $date = now()->subDays($daysToKeep);

            SystemLog::where('created_at', '<', $date)->delete();
            \App\Models\PerformanceLog::where('created_at', '<', $date)->delete();
            \App\Models\ApiLog::where('created_at', '<', $date)->delete();

            $this->info('system', 'Old logs cleaned', [
                'days_kept' => $daysToKeep,
                'cleaned_before' => $date->toDateTimeString()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to clean old logs: ' . $e->getMessage());
        }
    }
}
