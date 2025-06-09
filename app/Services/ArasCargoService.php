<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingDocument;
use App\Models\OrderLog;
use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ArasCargoService
{
    protected $username;
    protected $password;
    protected $customerCode;
    protected $apiUrl;
    protected $webhookSecret;
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->username = config('services.aras.username');
        $this->password = config('services.aras.password');
        $this->customerCode = config('services.aras.customer_code');
        $this->apiUrl = config('services.aras.api_url');
        $this->webhookSecret = config('services.aras.webhook_secret');
        $this->systemLog = $systemLog;
    }

    /**
     * Create shipment for order
     */
    public function createShipment(Order $order): array
    {
        try {
            // Check if shipment already exists
            if ($order->shipment) {
                return [
                    'success' => false,
                    'error' => 'Bu sipariş için zaten kargo kaydı mevcut.',
                ];
            }

            // Prepare shipment data
            $shipmentData = $this->prepareShipmentData($order);

            // Make API request
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->post($this->apiUrl . '/setOrder', $shipmentData);

            if (!$response->successful()) {
                throw new \Exception('Aras Kargo API hatası: ' . $response->status());
            }

            $result = $response->json();

            // Check API response
            if (!isset($result['result']) || $result['result'] !== true) {
                throw new \Exception($result['message'] ?? 'Kargo oluşturulamadı');
            }

            // Create shipment record
            $shipment = Shipment::create([
                'order_id' => $order->id,
                'carrier' => 'aras',
                'tracking_number' => $result['trackingNumber'],
                'status' => Shipment::STATUS_CREATED,
                'weight' => $this->calculateWeight($order),
                'desi' => $this->calculateDesi($order),
                'is_dropshipping' => $order->is_dropshipping,
                'shipped_at' => now(),
            ]);

            // Generate and save barcode PDF
            if (isset($result['barcodeData'])) {
                $barcodePath = $this->saveBarcodeDocument($order, $result['barcodeData']);
                $shipment->update(['barcode_pdf' => $barcodePath]);

                // Create shipping document record
                ShippingDocument::create([
                    'order_id' => $order->id,
                    'shipment_id' => $shipment->id,
                    'document_type' => ShippingDocument::TYPE_BARCODE,
                    'file_path' => $barcodePath,
                    'file_name' => 'barcode_' . $shipment->tracking_number . '.pdf',
                    'file_size' => Storage::disk('public')->size($barcodePath),
                    'uploaded_by' => auth()->id() ?? 1,
                ]);
            }

            // Update order status
            $order->markAsShipped($shipment->tracking_number);

            // Log shipment creation
            OrderLog::create([
                'order_id' => $order->id,
                'user_id' => auth()->id(),
                'action' => OrderLog::ACTION_SHIPPED,
                'description' => 'Kargo oluşturuldu',
                'new_value' => [
                    'carrier' => 'aras',
                    'tracking_number' => $shipment->tracking_number,
                ],
            ]);

            $this->systemLog->info('shipping', 'Aras Cargo shipment created', [
                'order_id' => $order->id,
                'tracking_number' => $shipment->tracking_number,
            ]);

            return [
                'success' => true,
                'shipment' => $shipment,
                'tracking_number' => $shipment->tracking_number,
                'barcode_url' => $shipment->barcode_pdf_url,
            ];

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Aras Cargo shipment creation failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], $e);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Track shipment
     */
    public function trackShipment(string $trackingNumber): array
    {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->post($this->apiUrl . '/getOrderInfo', [
                    'trackingNumber' => $trackingNumber,
                ]);

            if (!$response->successful()) {
                throw new \Exception('Aras Kargo API hatası: ' . $response->status());
            }

            $result = $response->json();

            if (!isset($result['result']) || $result['result'] !== true) {
                throw new \Exception($result['message'] ?? 'Kargo bilgisi alınamadı');
            }

            // Map Aras status to our status
            $status = $this->mapCargoStatus($result['status'] ?? '');

            // Update shipment if exists
            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();
            if ($shipment) {
                $shipment->updateStatus($status, $result['statusDescription'] ?? '');

                // Update delivery info if delivered
                if ($status === Shipment::STATUS_DELIVERED && isset($result['deliveryDate'])) {
                    $shipment->update([
                        'delivered_at' => $result['deliveryDate'],
                        'delivery_signature' => $result['receiverName'] ?? null,
                    ]);
                }
            }

            return [
                'success' => true,
                'tracking_number' => $trackingNumber,
                'status' => $status,
                'status_description' => $result['statusDescription'] ?? '',
                'location' => $result['currentLocation'] ?? '',
                'movements' => $result['movements'] ?? [],
                'delivery_date' => $result['deliveryDate'] ?? null,
                'receiver_name' => $result['receiverName'] ?? null,
            ];

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Aras Cargo tracking failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ], $e);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel shipment
     */
    public function cancelShipment(Shipment $shipment): bool
    {
        try {
            if ($shipment->carrier !== 'aras') {
                throw new \Exception('Bu kargo Aras Kargo ile gönderilmemiş');
            }

            if (in_array($shipment->status, [Shipment::STATUS_DELIVERED, Shipment::STATUS_RETURNED])) {
                throw new \Exception('Teslim edilmiş veya iade edilmiş kargolar iptal edilemez');
            }

            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->post($this->apiUrl . '/cancelOrder', [
                    'trackingNumber' => $shipment->tracking_number,
                    'reason' => 'Müşteri talebi',
                ]);

            if (!$response->successful()) {
                throw new \Exception('Aras Kargo API hatası: ' . $response->status());
            }

            $result = $response->json();

            if (!isset($result['result']) || $result['result'] !== true) {
                throw new \Exception($result['message'] ?? 'Kargo iptal edilemedi');
            }

            // Update shipment status
            $shipment->updateStatus(Shipment::STATUS_CANCELLED, 'İptal edildi');

            $this->systemLog->info('shipping', 'Aras Cargo shipment cancelled', [
                'shipment_id' => $shipment->id,
                'tracking_number' => $shipment->tracking_number,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Aras Cargo cancellation failed', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }

    /**
     * Handle webhook status update
     */
    public function handleStatusUpdate(array $data): bool
    {
        try {
            $trackingNumber = $data['trackingNumber'] ?? null;

            if (!$trackingNumber) {
                throw new \Exception('Tracking number not provided');
            }

            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();

            if (!$shipment) {
                $this->systemLog->warning('shipping', 'Shipment not found for webhook', [
                    'tracking_number' => $trackingNumber,
                ]);
                return false;
            }

            // Map status
            $status = $this->mapCargoStatus($data['status'] ?? '');
            $description = $data['statusDescription'] ?? '';

            // Update shipment
            $shipment->updateStatus($status, $description);

            // Send notification based on status
            $this->sendStatusNotification($shipment, $status);

            return true;

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Webhook status update failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }

    /**
     * Handle webhook delivery
     */
    public function handleDelivery(array $data): bool
    {
        try {
            $trackingNumber = $data['trackingNumber'] ?? null;

            if (!$trackingNumber) {
                throw new \Exception('Tracking number not provided');
            }

            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();

            if (!$shipment) {
                return false;
            }

            // Mark as delivered
            $shipment->markAsDelivered($data['receiverName'] ?? null);

            // Update order
            $shipment->order->markAsDelivered();

            // Send notification
            Notification::createOrderNotification(
                $shipment->order,
                Notification::TYPE_ORDER_DELIVERED
            );

            return true;

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Webhook delivery handling failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }

    /**
     * Handle webhook return
     */
    public function handleReturn(array $data): bool
    {
        try {
            $trackingNumber = $data['trackingNumber'] ?? null;

            if (!$trackingNumber) {
                throw new \Exception('Tracking number not provided');
            }

            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();

            if (!$shipment) {
                return false;
            }

            // Update status
            $shipment->updateStatus(Shipment::STATUS_RETURNED, $data['returnReason'] ?? 'İade edildi');

            // Handle order return logic here
            // ...

            return true;

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Webhook return handling failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(array $data, string $signature): bool
    {
        $payload = json_encode($data);
        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Prepare shipment data for API
     */
    protected function prepareShipmentData(Order $order): array
    {
        $user = $order->user;
        $company = $user->company;

        // Parse shipping address
        $addressParts = $this->parseAddress($order->shipping_address);

        return [
            'orderNumber' => $order->order_number,
            'customerCode' => $this->customerCode,
            'receiverName' => $order->shipping_contact_name ?: $company->company_name,
            'receiverPhone' => $order->shipping_contact_phone ?: $user->phone,
            'receiverAddress' => $addressParts['address'],
            'receiverCity' => $addressParts['city'],
            'receiverDistrict' => $addressParts['district'],
            'receiverPostalCode' => $addressParts['postal_code'],
            'paymentType' => 'P', // P: Gönderici Ödemeli, A: Alıcı Ödemeli
            'productType' => 'K', // K: Koli, D: Dosya, P: Paket
            'deliveryType' => $order->delivery_type === 'express' ? 'E' : 'N', // E: Express, N: Normal
            'pieceCount' => 1,
            'weight' => $this->calculateWeight($order),
            'desi' => $this->calculateDesi($order),
            'content' => $this->prepareContentDescription($order),
            'collectionType' => '0', // 0: Adresten Alım, 1: Şubeden Teslim
            'invoiceNumber' => $order->order_number,
            'invoiceAmount' => $order->grand_total,
            'smsNotification' => true,
            'emailNotification' => true,
            'emailAddress' => $user->email,
        ];
    }

    /**
     * Map Aras cargo status to our status
     */
    protected function mapCargoStatus(string $arasStatus): string
    {
        $mapping = [
            'CREATED' => Shipment::STATUS_CREATED,
            'PICKED_UP' => Shipment::STATUS_PICKED_UP,
            'TRANSIT' => Shipment::STATUS_IN_TRANSIT,
            'TRANSFER' => Shipment::STATUS_IN_TRANSIT,
            'DISTRIBUTION' => Shipment::STATUS_OUT_FOR_DELIVERY,
            'DELIVERED' => Shipment::STATUS_DELIVERED,
            'RETURNED' => Shipment::STATUS_RETURNED,
            'LOST' => Shipment::STATUS_LOST,
        ];

        return $mapping[strtoupper($arasStatus)] ?? Shipment::STATUS_IN_TRANSIT;
    }

    /**
     * Calculate total weight for order
     */
    protected function calculateWeight(Order $order): float
    {
        $weight = 0;

        foreach ($order->items as $item) {
            if ($item->product->weight) {
                $weight += $item->product->weight * $item->quantity;
            }
        }

        // Minimum 1 kg
        return max($weight, 1);
    }

    /**
     * Calculate desi for order
     */
    protected function calculateDesi(Order $order): float
    {
        // Simplified calculation - would need product dimensions in real implementation
        $itemCount = $order->items->sum('quantity');

        // Assume average 10x10x10 cm per item
        $totalVolume = $itemCount * 1000; // cm³
        $desi = $totalVolume / 3000;

        // Minimum 1 desi
        return max($desi, 1);
    }

    /**
     * Parse address into components
     */
    protected function parseAddress(string $address): array
    {
        // Simple parsing - in production, use a proper address parser
        $lines = explode("\n", $address);

        return [
            'address' => $lines[0] ?? '',
            'district' => $lines[1] ?? '',
            'city' => $lines[2] ?? '',
            'postal_code' => $lines[3] ?? '',
        ];
    }

    /**
     * Prepare content description
     */
    protected function prepareContentDescription(Order $order): string
    {
        $items = [];

        foreach ($order->items->take(3) as $item) {
            $items[] = $item->product->name . ' (' . $item->quantity . ')';
        }

        if ($order->items->count() > 3) {
            $items[] = '...';
        }

        return implode(', ', $items);
    }

    /**
     * Save barcode document
     */
    protected function saveBarcodeDocument(Order $order, string $barcodeData): string
    {
        $fileName = 'barcode_' . $order->order_number . '_' . time() . '.pdf';
        $filePath = 'shipping/barcodes/' . date('Y/m') . '/' . $fileName;

        // Decode base64 data
        $pdfContent = base64_decode($barcodeData);

        // Save to storage
        Storage::disk('public')->put($filePath, $pdfContent);

        return $filePath;
    }

    /**
     * Send status notification
     */
    protected function sendStatusNotification(Shipment $shipment, string $status): void
    {
        $notificationTypes = [
            Shipment::STATUS_PICKED_UP => Notification::TYPE_ORDER_SHIPPED,
            Shipment::STATUS_OUT_FOR_DELIVERY => null, // Optional notification
            Shipment::STATUS_DELIVERED => Notification::TYPE_ORDER_DELIVERED,
        ];

        $type = $notificationTypes[$status] ?? null;

        if ($type) {
            Notification::createOrderNotification($shipment->order, $type);
        }
    }

    /**
     * Get service points (branches)
     */
    public function getServicePoints(string $city, string $district = null): array
    {
        try {
            $params = [
                'city' => $city,
            ];

            if ($district) {
                $params['district'] = $district;
            }

            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->get($this->apiUrl . '/getServicePoints', $params);

            if (!$response->successful()) {
                throw new \Exception('Aras Kargo API hatası: ' . $response->status());
            }

            $result = $response->json();

            return [
                'success' => true,
                'service_points' => $result['servicePoints'] ?? [],
            ];

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Failed to get service points', [
                'city' => $city,
                'district' => $district,
                'error' => $e->getMessage(),
            ], $e);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'service_points' => [],
            ];
        }
    }

    /**
     * Calculate shipping cost
     */
    public function calculateShippingCost(
        string $fromCity,
        string $toCity,
        float $weight,
        float $desi,
        string $deliveryType = 'standard'
    ): array {
        try {
            $response = Http::withBasicAuth($this->username, $this->password)
                ->timeout(30)
                ->post($this->apiUrl . '/calculatePrice', [
                    'fromCity' => $fromCity,
                    'toCity' => $toCity,
                    'weight' => $weight,
                    'desi' => $desi,
                    'deliveryType' => $deliveryType === 'express' ? 'E' : 'N',
                    'customerCode' => $this->customerCode,
                ]);

            if (!$response->successful()) {
                throw new \Exception('Aras Kargo API hatası: ' . $response->status());
            }

            $result = $response->json();

            return [
                'success' => true,
                'cost' => $result['price'] ?? 0,
                'currency' => 'TRY',
                'estimated_days' => $result['estimatedDays'] ?? 2,
            ];

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Failed to calculate shipping cost', [
                'from' => $fromCity,
                'to' => $toCity,
                'error' => $e->getMessage(),
            ], $e);

            // Return default values
            return [
                'success' => false,
                'cost' => 25.00, // Default cost
                'currency' => 'TRY',
                'estimated_days' => 3,
                'error' => $e->getMessage(),
            ];
        }
    }
}
