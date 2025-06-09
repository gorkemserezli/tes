<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Product;
use App\Models\Company;
use App\Models\BalanceTransaction;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SalesReportExport;
use App\Exports\ProductReportExport;
use App\Exports\CustomerReportExport;

class ReportController extends Controller
{
    /**
     * Sales report
     */
    public function sales(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'group_by' => 'in:day,week,month,year',
            'status' => 'in:all,paid,pending',
        ]);

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();
        $groupBy = $request->get('group_by', 'day');
        $status = $request->get('status', 'paid');

        // Base query
        $query = Order::whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($status !== 'all') {
            if ($status === 'paid') {
                $query->where('payment_status', Order::PAYMENT_PAID);
            } else {
                $query->where('payment_status', '!=', Order::PAYMENT_PAID);
            }
        }

        // Get summary
        $summary = [
            'total_orders' => (clone $query)->count(),
            'total_revenue' => (clone $query)->sum('grand_total'),
            'total_products_sold' => OrderItem::whereIn('order_id', (clone $query)->pluck('id'))
                ->sum('quantity'),
            'average_order_value' => (clone $query)->avg('grand_total') ?? 0,
            'total_shipping' => (clone $query)->sum('shipping_cost'),
            'total_discount' => (clone $query)->sum('discount_total'),
            'total_vat' => (clone $query)->sum('vat_total'),
        ];

        // Group data by period
        $groupedData = $this->groupSalesData($query, $groupBy);

        // Top selling products in period
        $topProducts = $this->getTopProductsInPeriod($dateFrom, $dateTo, 10);

        // Payment method breakdown
        $paymentBreakdown = (clone $query)
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(grand_total) as total'))
            ->groupBy('payment_method')
            ->get();

        // Customer breakdown
        $topCustomers = $this->getTopCustomersInPeriod($dateFrom, $dateTo, 10);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'summary' => $summary,
                'chart_data' => $groupedData,
                'top_products' => $topProducts,
                'payment_breakdown' => $paymentBreakdown,
                'top_customers' => $topCustomers,
            ],
        ]);
    }

    /**
     * Product report
     */
    public function products(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'category_id' => 'nullable|exists:categories,id',
            'sort_by' => 'in:quantity,revenue,views',
        ]);

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();
        $sortBy = $request->get('sort_by', 'revenue');

        // Product performance
        $productPerformance = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                'products.base_price',
                'products.stock_quantity',
                'categories.name as category_name',
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total_price) as total_revenue'),
                DB::raw('AVG(order_items.unit_price) as avg_selling_price')
            )
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->when($request->category_id, function ($q, $categoryId) {
                $q->where('products.category_id', $categoryId);
            })
            ->groupBy('products.id', 'products.name', 'products.sku', 'products.base_price',
                'products.stock_quantity', 'categories.name');

        // Apply sorting
        switch ($sortBy) {
            case 'quantity':
                $productPerformance->orderByDesc('total_quantity');
                break;
            case 'views':
                $productPerformance->orderByDesc('products.view_count');
                break;
            default:
                $productPerformance->orderByDesc('total_revenue');
        }

        $products = $productPerformance->limit(100)->get();

        // Stock analysis
        $stockAnalysis = [
            'low_stock_products' => Product::where('is_active', true)
                ->where('stock_quantity', '<', 10)
                ->count(),
            'out_of_stock_products' => Product::where('is_active', true)
                ->where('stock_quantity', 0)
                ->count(),
            'total_stock_value' => Product::where('is_active', true)
                ->sum(DB::raw('stock_quantity * base_price')),
        ];

        // Stock movements summary
        $stockMovements = StockMovement::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(ABS(quantity)) as total_quantity'))
            ->groupBy('type')
            ->get();

        // Category performance
        $categoryPerformance = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->select(
                'categories.id',
                'categories.name',
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total_price) as total_revenue')
            )
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'products' => $products,
                'stock_analysis' => $stockAnalysis,
                'stock_movements' => $stockMovements,
                'category_performance' => $categoryPerformance,
            ],
        ]);
    }

    /**
     * Customer report
     */
    public function customers(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'group_id' => 'nullable|exists:customer_groups,id',
        ]);

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();

        // Customer statistics
        $newCustomers = User::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('is_admin', false)
            ->count();

        $activeCustomers = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->distinct('user_id')
            ->count('user_id');

        $returningCustomers = User::whereHas('orders', function ($q) use ($dateFrom, $dateTo) {
            $q->whereBetween('created_at', [$dateFrom, $dateTo]);
        })
            ->whereHas('orders', function ($q) use ($dateFrom) {
                $q->where('created_at', '<', $dateFrom);
            })
            ->count();

        // Customer lifetime value
        $customerLifetimeValue = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('SUM(orders.grand_total) as lifetime_value'),
                DB::raw('AVG(orders.grand_total) as avg_order_value'),
                DB::raw('MIN(orders.created_at) as first_order_date'),
                DB::raw('MAX(orders.created_at) as last_order_date')
            )
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->where('users.is_admin', false)
            ->when($request->group_id, function ($q, $groupId) {
                $q->join('user_groups', 'users.id', '=', 'user_groups.user_id')
                    ->where('user_groups.group_id', $groupId);
            })
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderByDesc('lifetime_value')
            ->limit(50)
            ->get();

        // Geographic distribution
        $geographicDistribution = Company::select('city', DB::raw('COUNT(*) as count'))
            ->whereHas('user.orders', function ($q) use ($dateFrom, $dateTo) {
                $q->whereBetween('created_at', [$dateFrom, $dateTo]);
            })
            ->groupBy('city')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        // Customer group performance
        $groupPerformance = DB::table('customer_groups')
            ->leftJoin('user_groups', 'customer_groups.id', '=', 'user_groups.group_id')
            ->leftJoin('users', 'user_groups.user_id', '=', 'users.id')
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->select(
                'customer_groups.id',
                'customer_groups.name',
                'customer_groups.discount_percentage',
                DB::raw('COUNT(DISTINCT users.id) as customer_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN orders.created_at BETWEEN "' . $dateFrom . '" AND "' . $dateTo . '" THEN orders.id END) as order_count'),
                DB::raw('SUM(CASE WHEN orders.created_at BETWEEN "' . $dateFrom . '" AND "' . $dateTo . '" AND orders.payment_status = "paid" THEN orders.grand_total ELSE 0 END) as total_revenue')
            )
            ->where('customer_groups.is_active', true)
            ->groupBy('customer_groups.id', 'customer_groups.name', 'customer_groups.discount_percentage')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'summary' => [
                    'new_customers' => $newCustomers,
                    'active_customers' => $activeCustomers,
                    'returning_customers' => $returningCustomers,
                    'customer_retention_rate' => $activeCustomers > 0
                        ? round(($returningCustomers / $activeCustomers) * 100, 2)
                        : 0,
                ],
                'customer_lifetime_value' => $customerLifetimeValue,
                'geographic_distribution' => $geographicDistribution,
                'group_performance' => $groupPerformance,
            ],
        ]);
    }

    /**
     * Stock report
     */
    public function stock(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'category_id' => 'nullable|exists:categories,id',
            'low_stock_only' => 'boolean',
        ]);

        // Current stock status
        $stockQuery = Product::with('category')
            ->when($request->category_id, function ($q, $categoryId) {
                $q->where('category_id', $categoryId);
            })
            ->when($request->low_stock_only, function ($q) {
                $q->where('stock_quantity', '<', 10);
            });

        $products = $stockQuery->get()->map(function ($product) {
            $stockValue = $product->stock_quantity * $product->base_price;

            // Get recent movements
            $recentMovements = StockMovement::where('product_id', $product->id)
                ->latest()
                ->limit(5)
                ->get();

            // Calculate average daily usage (last 30 days)
            $dailyUsage = StockMovement::where('product_id', $product->id)
                ->where('type', 'out')
                ->where('created_at', '>=', now()->subDays(30))
                ->sum('quantity');
            $avgDailyUsage = abs($dailyUsage) / 30;

            // Days until stockout
            $daysUntilStockout = $avgDailyUsage > 0
                ? round($product->stock_quantity / $avgDailyUsage)
                : null;

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'category' => $product->category->name ?? '-',
                'current_stock' => $product->stock_quantity,
                'stock_value' => $stockValue,
                'avg_daily_usage' => round($avgDailyUsage, 2),
                'days_until_stockout' => $daysUntilStockout,
                'status' => $product->stock_quantity === 0 ? 'out_of_stock' :
                    ($product->stock_quantity < 10 ? 'low_stock' : 'in_stock'),
                'recent_movements' => $recentMovements->map(function ($movement) {
                    return [
                        'type' => $movement->type,
                        'quantity' => $movement->quantity,
                        'date' => $movement->created_at->format('d.m.Y H:i'),
                        'description' => $movement->description,
                    ];
                }),
            ];
        });

        // Stock movement summary for period
        $movementSummary = [];
        if ($request->date_from && $request->date_to) {
            $dateFrom = Carbon::parse($request->date_from)->startOfDay();
            $dateTo = Carbon::parse($request->date_to)->endOfDay();

            $movementSummary = StockMovement::whereBetween('created_at', [$dateFrom, $dateTo])
                ->select(
                    'type',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as quantity_in'),
                    DB::raw('SUM(CASE WHEN quantity < 0 THEN ABS(quantity) ELSE 0 END) as quantity_out')
                )
                ->groupBy('type')
                ->get();
        }

        // Overall statistics
        $statistics = [
            'total_products' => Product::where('is_active', true)->count(),
            'in_stock_products' => Product::where('is_active', true)->where('stock_quantity', '>', 0)->count(),
            'low_stock_products' => Product::where('is_active', true)->where('stock_quantity', '<', 10)->where('stock_quantity', '>', 0)->count(),
            'out_of_stock_products' => Product::where('is_active', true)->where('stock_quantity', 0)->count(),
            'total_stock_value' => Product::where('is_active', true)->sum(DB::raw('stock_quantity * base_price')),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products,
                'movement_summary' => $movementSummary,
                'statistics' => $statistics,
            ],
        ]);
    }

    /**
     * Balance report
     */
    public function balance(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $dateFrom = Carbon::parse($request->date_from)->startOfDay();
        $dateTo = Carbon::parse($request->date_to)->endOfDay();

        // Balance transactions summary
        $transactionSummary = BalanceTransaction::whereBetween('created_at', [$dateFrom, $dateTo])
            ->select(
                'type',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as total_in'),
                DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as total_out')
            )
            ->groupBy('type')
            ->get();

        // Top balance users
        $topBalanceUsers = Company::with('user')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->limit(20)
            ->get()
            ->map(function ($company) {
                return [
                    'company_name' => $company->company_name,
                    'user_name' => $company->user->name,
                    'balance' => $company->balance,
                    'credit_limit' => $company->credit_limit,
                    'remaining_credit' => $company->remaining_credit,
                ];
            });

        // Balance usage by payment method
        $balanceUsage = Order::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('payment_method', 'balance')
            ->where('payment_status', Order::PAYMENT_PAID)
            ->select(
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(grand_total) as total_amount')
            )
            ->first();

        // Manual adjustments
        $manualAdjustments = BalanceTransaction::whereBetween('created_at', [$dateFrom, $dateTo])
            ->where('type', 'manual_adjustment')
            ->with(['user', 'createdBy'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $dateFrom->format('Y-m-d'),
                    'to' => $dateTo->format('Y-m-d'),
                ],
                'transaction_summary' => $transactionSummary,
                'top_balance_users' => $topBalanceUsers,
                'balance_usage' => $balanceUsage,
                'manual_adjustments' => $manualAdjustments,
                'totals' => [
                    'total_balance' => Company::sum('balance'),
                    'total_credit_limit' => Company::sum('credit_limit'),
                    'total_credit_used' => Company::sum(DB::raw('credit_limit - remaining_credit')),
                ],
            ],
        ]);
    }

    /**
     * Export report
     */
    public function export(Request $request, $type)
    {
        $request->validate([
            'format' => 'in:csv,xlsx,pdf',
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        $format = $request->get('format', 'xlsx');
        $dateFrom = Carbon::parse($request->date_from);
        $dateTo = Carbon::parse($request->date_to);

        switch ($type) {
            case 'sales':
                $export = new SalesReportExport($dateFrom, $dateTo, $request->all());
                $fileName = 'sales_report_' . $dateFrom->format('Y-m-d') . '_' . $dateTo->format('Y-m-d');
                break;

            case 'products':
                $export = new ProductReportExport($dateFrom, $dateTo, $request->all());
                $fileName = 'product_report_' . $dateFrom->format('Y-m-d') . '_' . $dateTo->format('Y-m-d');
                break;

            case 'customers':
                $export = new CustomerReportExport($dateFrom, $dateTo, $request->all());
                $fileName = 'customer_report_' . $dateFrom->format('Y-m-d') . '_' . $dateTo->format('Y-m-d');
                break;

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Geçersiz rapor tipi.',
                ], 400);
        }

        $fileName .= '.' . $format;

        if ($format === 'pdf') {
            // For PDF, we need to create a view and use DomPDF
            return $this->exportPdf($type, $dateFrom, $dateTo, $request->all());
        }

        return Excel::download($export, $fileName);
    }

    /**
     * Group sales data by period
     */
    protected function groupSalesData($query, $groupBy)
    {
        switch ($groupBy) {
            case 'year':
                $format = '%Y';
                break;
            case 'month':
                $format = '%Y-%m';
                break;
            case 'week':
                $format = '%Y-%u';
                break;
            default:
                $format = '%Y-%m-%d';
        }

        return $query->select(
            DB::raw("DATE_FORMAT(created_at, '{$format}') as period"),
            DB::raw('COUNT(*) as order_count'),
            DB::raw('SUM(grand_total) as total_revenue'),
            DB::raw('AVG(grand_total) as avg_order_value')
        )
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    /**
     * Get top products in period
     */
    protected function getTopProductsInPeriod($dateFrom, $dateTo, $limit = 10)
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.name',
                'products.sku',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.total_price) as total_revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as order_count')
            )
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->orderByDesc('total_revenue')
            ->limit($limit)
            ->get();
    }

    /**
     * Get top customers in period
     */
    protected function getTopCustomersInPeriod($dateFrom, $dateTo, $limit = 10)
    {
        return DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->leftJoin('companies', 'users.id', '=', 'companies.user_id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'companies.company_name',
                DB::raw('COUNT(DISTINCT orders.id) as order_count'),
                DB::raw('SUM(orders.grand_total) as total_spent'),
                DB::raw('AVG(orders.grand_total) as avg_order_value')
            )
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo])
            ->where('orders.payment_status', Order::PAYMENT_PAID)
            ->where('users.is_admin', false)
            ->groupBy('users.id', 'users.name', 'users.email', 'companies.company_name')
            ->orderByDesc('total_spent')
            ->limit($limit)
            ->get();
    }

    /**
     * Export as PDF
     */
    protected function exportPdf($type, $dateFrom, $dateTo, $params)
    {
        // This would need PDF views to be created
        // For now, return a simple response
        return response()->json([
            'success' => false,
            'message' => 'PDF export özelliği henüz hazır değil.',
        ], 501);
    }
}
