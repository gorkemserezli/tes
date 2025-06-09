<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendLowStockAlerts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Get products with low stock
        $lowStockProducts = Product::where('is_active', true)
            ->where('stock_quantity', '<', 10)
            ->where('stock_quantity', '>', 0)
            ->get();

        if ($lowStockProducts->isEmpty()) {
            Log::info('No low stock products found.');
            return;
        }

        // Get admin users
        $admins = User::where('is_admin', true)
            ->where('is_active', true)
            ->get();

        // Send notification to each admin
        foreach ($admins as $admin) {
            $admin->notify(new LowStockNotification($lowStockProducts));
        }

        Log::info('Low stock alerts sent to ' . $admins->count() . ' admins for ' . $lowStockProducts->count() . ' products.');
    }
}
