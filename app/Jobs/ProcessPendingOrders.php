<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPendingOrders implements ShouldQueue
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
        // Auto-cancel orders that are pending payment for more than 24 hours
        $cutoffTime = Carbon::now()->subHours(24);

        $pendingOrders = Order::where('status', Order::STATUS_PENDING)
            ->where('payment_status', Order::PAYMENT_PENDING)
            ->where('created_at', '<', $cutoffTime)
            ->get();

        foreach ($pendingOrders as $order) {
            try {
                // Cancel the order
                $order->cancel('Ödeme süresi doldu - otomatik iptal');

                // Send notification to customer
                Notification::create([
                    'user_id' => $order->user_id,
                    'type' => Notification::TYPE_ORDER_CANCELLED,
                    'title' => 'Siparişiniz İptal Edildi',
                    'message' => 'Siparişiniz ödeme süresi dolduğu için otomatik olarak iptal edildi. Sipariş No: ' . $order->order_number,
                    'data' => [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'reason' => 'payment_timeout',
                    ],
                ]);

                Log::info('Order auto-cancelled due to payment timeout', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to auto-cancel order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($pendingOrders->count() > 0) {
            Log::info('Processed ' . $pendingOrders->count() . ' pending orders for auto-cancellation.');
        }
    }
}
