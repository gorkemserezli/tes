<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Product;
use App\Models\Company;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard overview
     */
    public function index(Request $request)
    {
        $period = $request->get('period', 'month'); // today, week, month, year
        $dateRange = $this->getDateRange($period);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $this->getSummaryStats($dateRange),
                'revenue_chart' => $this->getRevenueChart($dateRange),
                'order_chart' => $this->getOrderChart($dateRange),
                'top_products' => $this->getTopProducts($dateRange),
                'recent_orders' => $this->getRecentOrders(),
                'pending_approvals' => $this->getPendingApprovals(),
                'low_stock_products' => $this->getLowStockProducts(),
                'payment_distribution' => $this->getPaymentDistribution($dateRange),
            ],
        ]);
    }

    /**
     * Get summary statistics
     */
    public function stats(Request $request)
    {
        $period = $request->get('period', 'month');
        $dateRange = $this->getDateRange($period);
        $previousRange = $this->getPreviousDateRange($period);

        // Current period stats
        $currentStats = $this->calculateStats($dateRange);

        // Previous period stats for comparison
        $previousStats = $this->calculateStats($previousRange);

        // Calculate growth percentages
        $stats = [];
        foreach ($currentStats as $key => $value) {
            $previousValue = $previousStats[$key] ?? 0;
            $growth = $previousValue > 0 ? (($value - $previousValue) / $previousValue) * 100 : 0;

            $stats[$key] = [
                'value' => $value,
                'previous' => $previousValue,
                'growth' => round($growth, 2),
                'growth_type' => $growth >= 0 ? 'increase' : 'decrease',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $period,
                'date_range' => [
                    'start' => $dateRange['start']->format('Y-m-d'),
                    'end' => $dateRange['end']->format('Y-m-d'),
                ],
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Get summary statistics
     */
    protected function getSummaryStats($dateRange)
    {
        return [
            'total_revenue' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('payment_status', Order::PAYMENT_PAID)
                ->sum('grand_total'),

            'total_orders' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->count(),

            'new_customers' => User::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('is_admin', false)
                ->count(),

            'active_customers' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->distinct('user_id')
                ->count('user_id'),

            'average_order_value' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                    ->where('payment_status', Order::PAYMENT_PAID)
                    ->avg('grand_total') ?? 0,

            'pending_orders' => Order::where('status', Order::STATUS_PENDING)->count(),

            'processing_orders' => Order::whereIn('status', [Order::STATUS_CONFIRMED, Order::STATUS_PROCESSING])
                ->count(),

            'low_stock_count' => Product::where('is_active', true)
                ->where('stock_quantity', '<', 10)
                ->count(),
        ];
    }

    /**
     * Get revenue chart data
     */
    protected function getRevenueChart($dateRange)
    {
        $days = $dateRange['start']->diffInDays($dateRange['end']);

        if ($days <= 31) {
            // Daily data
            $data = Order::selectRaw('DATE(created_at) as date, SUM(grand_total) as revenue')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('payment_status', Order::PAYMENT_PAID)
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $format = 'd.m';
        } elseif ($days <= 365) {
            // Monthly data
            $data = Order::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as date, SUM(grand_total) as revenue')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('payment_status', Order::PAYMENT_PAID)
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $format = 'M Y';
        } else {
            // Yearly data
            $data = Order::selectRaw('YEAR(created_at) as date, SUM(grand_total) as revenue')
                ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('payment_status', Order::PAYMENT_PAID)
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $format = 'Y';
        }

        return [
            'labels' => $data->pluck('date')->map(function ($date) use ($format) {
                return Carbon::parse($date)->format($format);
            }),
            'data' => $data->pluck('revenue'),
            'total' => $data->sum('revenue'),
        ];
    }

    /**
     * Get order chart data
     */
    protected function getOrderChart($dateRange)
    {
        $statuses = [
            Order::STATUS_PENDING => 'Beklemede',
            Order::STATUS_CONFIRMED => 'Onaylandı',
            Order::STATUS_PROCESSING => 'Hazırlanıyor',
            Order::STATUS_SHIPPED => 'Kargoda',
            Order::STATUS_DELIVERED => 'Teslim Edildi',
            Order::STATUS_CANCELLED => 'İptal',
        ];

        $data = Order::selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        return [
            'labels' => array_values($statuses),
            'data' => array_map(function ($status) use ($data) {
                return $data[$status] ?? 0;
            }, array_keys($statuses)),
        ];
    }

    /**
     * Get top selling products
     */
    protected function getTopProducts($dateRange, $limit = 10)
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total_price) as total_revenue')
            )
            ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent orders
     */
    protected function getRecentOrders($limit = 10)
    {
        return Order::with(['user', 'items'])
            ->latest()
            ->limit($limit)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->user->name,
                    'company' => $order->user->company->company_name ?? '-',
                    'total' => $order->grand_total,
                    'status' => $order->status,
                    'status_label' => $order->status_label,
                    'status_color' => $order->status_color,
                    'payment_status' => $order->payment_status,
                    'payment_status_label' => $order->payment_status_label,
                    'created_at' => $order->created_at->format('d.m.Y H:i'),
                ];
            });
    }

    /**
     * Get pending approvals
     */
    protected function getPendingApprovals()
    {
        $pendingCompanies = Company::where('is_approved', false)
            ->with('user')
            ->latest()
            ->get()
            ->map(function ($company) {
                return [
                    'type' => 'company',
                    'id' => $company->id,
                    'name' => $company->company_name,
                    'user' => $company->user->name,
                    'tax_number' => $company->tax_number,
                    'created_at' => $company->created_at->format('d.m.Y H:i'),
                ];
            });

        $pendingPayments = PaymentTransaction::where('payment_method', 'bank_transfer')
            ->where('status', PaymentTransaction::STATUS_PENDING)
            ->whereNotNull('bank_receipt')
            ->with(['order.user'])
            ->latest()
            ->get()
            ->map(function ($payment) {
                return [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'order_number' => $payment->order->order_number,
                    'customer' => $payment->order->user->name,
                    'amount' => $payment->amount,
                    'bank' => $payment->bank_name,
                    'created_at' => $payment->created_at->format('d.m.Y H:i'),
                ];
            });

        return [
            'companies' => $pendingCompanies,
            'payments' => $pendingPayments,
            'total' => $pendingCompanies->count() + $pendingPayments->count(),
        ];
    }

    /**
     * Get low stock products
     */
    protected function getLowStockProducts($threshold = 10, $limit = 10)
    {
        return Product::where('is_active', true)
            ->where('stock_quantity', '<', $threshold)
            ->orderBy('stock_quantity')
            ->limit($limit)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock_quantity,
                    'min_order' => $product->min_order_quantity,
                    'category' => $product->category->name ?? '-',
                ];
            });
    }

    /**
     * Get payment method distribution
     */
    protected function getPaymentDistribution($dateRange)
    {
        $data = Order::selectRaw('payment_method, COUNT(*) as count, SUM(grand_total) as total')
            ->whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
            ->where('payment_status', Order::PAYMENT_PAID)
            ->groupBy('payment_method')
            ->get();

        $labels = [
            'credit_card' => 'Kredi Kartı',
            'bank_transfer' => 'Havale/EFT',
            'balance' => 'Cari Bakiye',
        ];

        return [
            'labels' => $data->pluck('payment_method')->map(fn($method) => $labels[$method] ?? $method),
            'counts' => $data->pluck('count'),
            'totals' => $data->pluck('total'),
            'percentages' => $data->map(function ($item) use ($data) {
                $totalAmount = $data->sum('total');
                return $totalAmount > 0 ? round(($item->total / $totalAmount) * 100, 2) : 0;
            }),
        ];
    }

    /**
     * Calculate statistics for a date range
     */
    protected function calculateStats($dateRange)
    {
        return [
            'revenue' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->where('payment_status', Order::PAYMENT_PAID)
                ->sum('grand_total'),

            'orders' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->count(),

            'customers' => Order::whereBetween('created_at', [$dateRange['start'], $dateRange['end']])
                ->distinct('user_id')
                ->count('user_id'),

            'products_sold' => DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->whereBetween('orders.created_at', [$dateRange['start'], $dateRange['end']])
                ->sum('order_items.quantity'),
        ];
    }

    /**
     * Get date range based on period
     */
    protected function getDateRange($period)
    {
        switch ($period) {
            case 'today':
                return [
                    'start' => Carbon::today(),
                    'end' => Carbon::today()->endOfDay(),
                ];

            case 'week':
                return [
                    'start' => Carbon::now()->startOfWeek(),
                    'end' => Carbon::now()->endOfWeek(),
                ];

            case 'month':
                return [
                    'start' => Carbon::now()->startOfMonth(),
                    'end' => Carbon::now()->endOfMonth(),
                ];

            case 'year':
                return [
                    'start' => Carbon::now()->startOfYear(),
                    'end' => Carbon::now()->endOfYear(),
                ];

            default:
                return [
                    'start' => Carbon::now()->startOfMonth(),
                    'end' => Carbon::now()->endOfMonth(),
                ];
        }
    }

    /**
     * Get previous date range for comparison
     */
    protected function getPreviousDateRange($period)
    {
        switch ($period) {
            case 'today':
                return [
                    'start' => Carbon::yesterday(),
                    'end' => Carbon::yesterday()->endOfDay(),
                ];

            case 'week':
                return [
                    'start' => Carbon::now()->subWeek()->startOfWeek(),
                    'end' => Carbon::now()->subWeek()->endOfWeek(),
                ];

            case 'month':
                return [
                    'start' => Carbon::now()->subMonth()->startOfMonth(),
                    'end' => Carbon::now()->subMonth()->endOfMonth(),
                ];

            case 'year':
                return [
                    'start' => Carbon::now()->subYear()->startOfYear(),
                    'end' => Carbon::now()->subYear()->endOfYear(),
                ];

            default:
                return [
                    'start' => Carbon::now()->subMonth()->startOfMonth(),
                    'end' => Carbon::now()->subMonth()->endOfMonth(),
                ];
        }
    }
}
