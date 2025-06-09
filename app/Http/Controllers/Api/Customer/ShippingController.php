<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\ArasCargoService;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    protected ArasCargoService $arasService;

    public function __construct(ArasCargoService $arasService)
    {
        $this->arasService = $arasService;
    }

    /**
     * Track shipment by order
     */
    public function trackOrder(Request $request, $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->with(['shipment', 'items.product'])
            ->firstOrFail();

        if (!$order->shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Bu sipariş için henüz kargo kaydı oluşturulmamış.',
            ], 404);
        }

        $shipment = $order->shipment;

        // Get latest tracking info from Aras
        if ($shipment->carrier === 'aras' && $shipment->tracking_number) {
            $trackingResult = $this->arasService->trackShipment($shipment->tracking_number);

            if ($trackingResult['success']) {
                // Tracking info is automatically updated in the service
                $shipment->refresh();
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'order_number' => $order->order_number,
                'shipment' => [
                    'tracking_number' => $shipment->tracking_number,
                    'carrier' => $shipment->carrier,
                    'carrier_label' => $shipment->carrier_label,
                    'status' => $shipment->status,
                    'status_label' => $shipment->status_label,
                    'status_color' => $shipment->status_color,
                    'status_description' => $shipment->status_description,
                    'tracking_url' => $shipment->tracking_url,
                    'shipped_at' => $shipment->shipped_at?->format('d.m.Y H:i'),
                    'delivered_at' => $shipment->delivered_at?->format('d.m.Y H:i'),
                    'estimated_delivery' => $shipment->estimated_delivery,
                    'is_delivered' => $shipment->isDelivered(),
                    'is_trackable' => $shipment->isTrackable(),
                ],
                'shipping_address' => $order->full_shipping_address,
            ],
        ]);
    }

    /**
     * Track shipment by tracking number
     */
    public function trackByNumber(Request $request)
    {
        $request->validate([
            'tracking_number' => 'required|string',
        ]);

        // Check if user owns this shipment
        $shipment = Shipment::where('tracking_number', $request->tracking_number)
            ->whereHas('order', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->first();

        if (!$shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Kargo bulunamadı veya size ait değil.',
            ], 404);
        }

        // Get latest tracking info
        if ($shipment->carrier === 'aras') {
            $trackingResult = $this->arasService->trackShipment($shipment->tracking_number);

            if (!$trackingResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Kargo takip bilgisi alınamadı: ' . $trackingResult['error'],
                ], 400);
            }

            // Refresh shipment data
            $shipment->refresh();

            return response()->json([
                'success' => true,
                'data' => array_merge($trackingResult, [
                    'order_number' => $shipment->order->order_number,
                    'carrier_label' => $shipment->carrier_label,
                    'tracking_url' => $shipment->tracking_url,
                ]),
            ]);
        }

        // For other carriers, return basic info
        return response()->json([
            'success' => true,
            'data' => [
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status,
                'status_label' => $shipment->status_label,
                'carrier' => $shipment->carrier,
                'carrier_label' => $shipment->carrier_label,
                'tracking_url' => $shipment->tracking_url,
                'order_number' => $shipment->order->order_number,
            ],
        ]);
    }

    /**
     * Download shipping document
     */
    public function downloadDocument(Request $request, $orderNumber, $documentId)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $document = $order->shippingDocuments()
            ->where('id', $documentId)
            ->firstOrFail();

        // Check if file exists
        if (!Storage::disk('public')->exists($document->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Belge bulunamadı.',
            ], 404);
        }

        return Storage::disk('public')->download(
            $document->file_path,
            $document->file_name,
            [
                'Content-Type' => $document->mime_type,
            ]
        );
    }

    /**
     * Get available service points
     */
    public function getServicePoints(Request $request)
    {
        $request->validate([
            'city' => 'required|string',
            'district' => 'nullable|string',
        ]);

        $result = $this->arasService->getServicePoints(
            $request->city,
            $request->district
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Servis noktaları alınamadı.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result['service_points'],
        ]);
    }

    /**
     * Calculate shipping cost
     */
    public function calculateCost(Request $request)
    {
        $request->validate([
            'to_city' => 'required|string',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'delivery_type' => 'nullable|in:standard,express',
        ]);

        // Calculate total weight and volume
        $totalWeight = 0;
        $totalDesi = 0;

        foreach ($request->items as $item) {
            $product = \App\Models\Product::find($item['product_id']);

            if ($product->weight) {
                $totalWeight += $product->weight * $item['quantity'];
            }
        }

        // Simple desi calculation
        $itemCount = collect($request->items)->sum('quantity');
        $totalDesi = max(($itemCount * 1000) / 3000, 1); // Min 1 desi

        // Get shipping cost from Aras
        $result = $this->arasService->calculateShippingCost(
            'ANKARA', // From city - should be from settings
            strtoupper($request->to_city),
            max($totalWeight, 1), // Min 1 kg
            $totalDesi,
            $request->delivery_type ?? 'standard'
        );

        return response()->json([
            'success' => $result['success'],
            'data' => [
                'cost' => $result['cost'],
                'formatted_cost' => number_format($result['cost'], 2, ',', '.') . ' ₺',
                'estimated_days' => $result['estimated_days'],
                'weight' => $totalWeight,
                'desi' => $totalDesi,
                'delivery_type' => $request->delivery_type ?? 'standard',
            ],
        ]);
    }

    /**
     * Get shipping options
     */
    public function getOptions(Request $request)
    {
        $options = [
            [
                'type' => 'standard',
                'label' => 'Standart Teslimat',
                'description' => '2-3 iş günü içinde teslimat',
                'icon' => 'truck',
                'estimated_days' => 3,
                'base_cost' => 25.00,
            ],
            [
                'type' => 'express',
                'label' => 'Hızlı Teslimat',
                'description' => '1 iş günü içinde teslimat',
                'icon' => 'lightning-bolt',
                'estimated_days' => 1,
                'base_cost' => 50.00,
            ],
            [
                'type' => 'pickup',
                'label' => 'Şubeden Teslim',
                'description' => 'En yakın Aras Kargo şubesinden teslim alın',
                'icon' => 'office-building',
                'estimated_days' => 2,
                'base_cost' => 15.00,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $options,
        ]);
    }
}
