<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderDetailResource;
use App\Models\Order;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\OrderLog;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class OrderController extends Controller
{
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->systemLog = $systemLog;
    }

    /**
     * List user's orders
     */
    public function index(Request $request)
    {
        $orders = Order::where('user_id', auth()->id())
            ->with(['items', 'shipment'])
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->payment_status, function ($query, $status) {
                $query->where('payment_status', $status);
            })
            ->when($request->search, function ($query, $search) {
                $query->where('order_number', 'like', "%{$search}%");
            })
            ->when($request->date_from, function ($query, $date) {
                $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                $query->whereDate('created_at', '<=', $date);
            })
            ->latest()
            ->paginate($request->per_page ?? 20);

        return OrderResource::collection($orders);
    }

    /**
     * Create new order
     */
    public function store(CreateOrderRequest $request)
    {
        $user = auth()->user();

        // Check if user has items in cart
        $cartItems = CartItem::where('user_id', $user->id)
            ->with('product')
            ->get();

        if ($cartItems->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Sepetinizde ürün bulunmamaktadır.',
            ], 400);
        }

        // Check product availability
        foreach ($cartItems as $cartItem) {
            if (!$cartItem->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => "'{$cartItem->product->name}' ürünü stokta yok veya satışta değil.",
                ], 400);
            }
        }

        DB::beginTransaction();

        try {
            // Create order
            $order = Order::create([
                'user_id' => $user->id,
                'order_type' => $request->order_type ?? 'cargo',
                'delivery_type' => $request->delivery_type ?? 'standard',
                'payment_method' => $request->payment_method,
                'shipping_address' => $request->use_different_shipping
                    ? $request->shipping_address
                    : $user->company->full_address,
                'shipping_contact_name' => $request->shipping_contact_name,
                'shipping_contact_phone' => $request->shipping_contact_phone,
                'billing_address' => $user->company->full_address,
                'billing_contact_name' => $user->company->company_name,
                'billing_contact_phone' => $user->phone,
                'use_different_shipping' => $request->use_different_shipping ?? false,
                'notes' => $request->notes,
                'subtotal' => 0,
                'vat_total' => 0,
                'shipping_cost' => $this->calculateShippingCost($request->delivery_type),
                'grand_total' => 0,
            ]);

            // Create order items and calculate totals
            $subtotal = 0;
            $vatTotal = 0;

            foreach ($cartItems as $cartItem) {
                $product = $cartItem->product;
                $unitPrice = $product->getPriceForUser($user, $cartItem->quantity);

                $orderItem = OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $product->vat_rate,
                    'discount_amount' => 0,
                ]);

                $subtotal += $orderItem->subtotal;
                $vatTotal += $orderItem->vat_amount;

                // Reserve stock
                $product->reserveStock($cartItem->quantity, $order->id);
            }

            // Update order totals
            $grandTotal = $subtotal + $vatTotal + $order->shipping_cost;

            $order->update([
                'subtotal' => $subtotal,
                'vat_total' => $vatTotal,
                'grand_total' => $grandTotal,
            ]);

            // Clear cart
            CartItem::clearCart($user);

            // Create order log
            OrderLog::logCreation($order, $user);

            // Send notification
            \App\Models\Notification::createOrderNotification(
                $order,
                \App\Models\Notification::TYPE_ORDER_CREATED
            );

            DB::commit();

            $this->systemLog->info('order', 'Order created', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'total' => $order->grand_total,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Siparişiniz başarıyla oluşturuldu.',
                'data' => new OrderDetailResource($order->load(['items.product', 'user.company'])),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('order', 'Order creation failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Sipariş oluşturulurken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Show order details
     */
    public function show($orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with([
                'items.product.images',
                'user.company',
                'payments',
                'shipment',
                'shippingDocuments',
                'logs' => function ($query) {
                    $query->latest()->limit(10);
                }
            ])
            ->firstOrFail();

        return new OrderDetailResource($order);
    }

    /**
     * Cancel order
     */
    public function cancel(Request $request, $orderNumber)
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (!$order->canBeCancelled()) {
            return response()->json([
                'success' => false,
                'message' => 'Bu sipariş iptal edilemez. Sadece onay bekleyen veya onaylanmış siparişler iptal edilebilir.',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $result = $order->cancel($request->reason);

            if (!$result) {
                throw new \Exception('Sipariş iptal edilemedi');
            }

            DB::commit();

            $this->systemLog->info('order', 'Order cancelled by customer', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Siparişiniz başarıyla iptal edildi.',
                'data' => new OrderResource($order->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('order', 'Order cancellation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Sipariş iptal edilirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Download order invoice
     */
    public function invoice($orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with(['items.product', 'user.company'])
            ->firstOrFail();

        // Check if order is paid
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return response()->json([
                'success' => false,
                'message' => 'Fatura sadece ödemesi tamamlanmış siparişler için indirilebilir.',
            ], 400);
        }

        $pdf = Pdf::loadView('pdf.invoice', compact('order'));

        return $pdf->download('fatura_' . $order->order_number . '.pdf');
    }

    /**
     * Get order tracking info
     */
    public function tracking($orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with(['shipment'])
            ->firstOrFail();

        if (!$order->shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Bu sipariş için henüz kargo bilgisi bulunmamaktadır.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'order_status' => $order->status,
                'order_status_label' => $order->status_label,
                'shipment' => [
                    'tracking_number' => $order->shipment->tracking_number,
                    'carrier' => $order->shipment->carrier,
                    'carrier_label' => $order->shipment->carrier_label,
                    'status' => $order->shipment->status,
                    'status_label' => $order->shipment->status_label,
                    'tracking_url' => $order->shipment->tracking_url,
                    'shipped_at' => $order->shipment->shipped_at?->format('d.m.Y H:i'),
                    'estimated_delivery' => $order->shipment->estimated_delivery,
                ],
            ],
        ]);
    }

    /**
     * Repeat order
     */
    public function repeat($orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with(['items.product'])
            ->firstOrFail();

        $user = auth()->user();
        $addedCount = 0;
        $failedItems = [];

        DB::beginTransaction();

        try {
            foreach ($order->items as $item) {
                try {
                    CartItem::addItem($user, $item->product, $item->quantity);
                    $addedCount++;
                } catch (\Exception $e) {
                    $failedItems[] = [
                        'product' => $item->product->name,
                        'reason' => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            if ($addedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Hiçbir ürün sepete eklenemedi.',
                    'failed_items' => $failedItems,
                ], 400);
            }

            $message = $addedCount . ' ürün sepete eklendi.';

            if (!empty($failedItems)) {
                $message .= ' ' . count($failedItems) . ' ürün eklenemedi.';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'added_count' => $addedCount,
                    'failed_items' => $failedItems,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Ürünler sepete eklenirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Calculate shipping cost based on delivery type
     */
    protected function calculateShippingCost($deliveryType): float
    {
        // This should be calculated based on actual shipping rates
        $costs = [
            'standard' => 25.00,
            'express' => 50.00,
            'pickup' => 0.00,
        ];

        return $costs[$deliveryType] ?? 25.00;
    }
}
