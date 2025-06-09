<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class GenerateDailySalesReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:daily-sales {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and send daily sales report to administrators';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $this->info('Generating daily sales report for: ' . $date->format('Y-m-d'));

        // Get sales data
        $salesData = $this->generateSalesData($date);

        // Get admin emails
        $adminEmails = User::where('is_admin', true)
            ->where('is_active', true)
            ->pluck('email')
            ->toArray();

        if (empty($adminEmails)) {
            $this->warn('No active admin users found to send report.');
            return Command::SUCCESS;
        }

        // Send email report
        $this->sendReport($salesData, $adminEmails, $date);

        $this->info('Daily sales report sent to ' . count($adminEmails) . ' administrators.');

        return Command::SUCCESS;
    }

    /**
     * Generate sales data for the given date
     */
    protected function generateSalesData(Carbon $date): array
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        // Total orders and revenue
        $orders = Order::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('payment_status', Order::PAYMENT_PAID)
            ->get();

        $totalOrders = $orders->count();
        $totalRevenue = $orders->sum('grand_total');
        $averageOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

        // Orders by status
        $ordersByStatus = Order::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Top selling products
        $topProducts = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$startOfDay, $endOfDay])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->select(
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total_price) as total_revenue')
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        // New customers
        $newCustomers = User::whereDate('created_at', $date)
            ->where('is_admin', false)
            ->count();

        // Payment method breakdown
        $paymentBreakdown = Order::whereBetween('created_at', [$startOfDay, $endOfDay])
            ->where('payment_status', Order::PAYMENT_PAID)
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(grand_total) as total'))
            ->groupBy('payment_method')
            ->get();

        return [
            'date' => $date->format('Y-m-d'),
            'total_orders' => $totalOrders,
            'total_revenue' => $totalRevenue,
            'average_order_value' => $averageOrderValue,
            'orders_by_status' => $ordersByStatus,
            'top_products' => $topProducts,
            'new_customers' => $newCustomers,
            'payment_breakdown' => $paymentBreakdown,
        ];
    }

    /**
     * Send the report via email
     */
    protected function sendReport(array $data, array $recipients, Carbon $date): void
    {
        Mail::send('emails.reports.daily-sales', ['data' => $data], function ($message) use ($recipients, $date) {
            $message->to($recipients)
                ->subject('Günlük Satış Raporu - ' . $date->format('d.m.Y'));
        });
    }
}
