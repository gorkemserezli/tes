<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\SendLowStockAlerts;
use App\Jobs\CleanOldLogs;
use App\Jobs\ProcessPendingOrders;
use App\Jobs\UpdateShipmentStatus;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Send low stock alerts every day at 9:00 AM
        $schedule->job(new SendLowStockAlerts)
            ->dailyAt('09:00')
            ->name('send-low-stock-alerts')
            ->withoutOverlapping();

        // Clean old logs every day at 2:00 AM
        $schedule->job(new CleanOldLogs(30))
            ->dailyAt('02:00')
            ->name('clean-old-logs')
            ->withoutOverlapping();

        // Process pending orders every hour
        $schedule->job(new ProcessPendingOrders)
            ->hourly()
            ->name('process-pending-orders')
            ->withoutOverlapping();

        // Update shipment statuses every 4 hours
        $schedule->job(new UpdateShipmentStatus)
            ->everyFourHours()
            ->name('update-shipment-status')
            ->withoutOverlapping();

        // Clear expired 2FA codes every 30 minutes
        $schedule->call(function () {
            \App\Models\User::where('two_factor_expires_at', '<', now())
                ->whereNotNull('two_factor_code')
                ->update([
                    'two_factor_code' => null,
                    'two_factor_expires_at' => null,
                ]);
        })->everyThirtyMinutes()->name('clear-expired-2fa-codes');

        // Generate daily sales report
        $schedule->command('report:daily-sales')
            ->dailyAt('23:55')
            ->name('generate-daily-sales-report');

        // Backup database
        $schedule->command('backup:run --only-db')
            ->dailyAt('03:00')
            ->name('backup-database');

        // Queue health check
        $schedule->command('queue:monitor redis:default --max=100')
            ->everyMinute()
            ->name('monitor-queue-size');

        // Clear old notifications (older than 90 days)
        $schedule->call(function () {
            \App\Models\Notification::where('created_at', '<', now()->subDays(90))
                ->delete();
        })->weekly()->sundays()->at('04:00')->name('clear-old-notifications');

        // Update product view statistics
        $schedule->call(function () {
            // This could be used to process/aggregate view counts
            \Illuminate\Support\Facades\Log::info('Product view statistics updated');
        })->dailyAt('01:00')->name('update-product-statistics');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
