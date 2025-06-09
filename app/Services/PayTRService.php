<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PayTRService
{
    protected $merchantId;
    protected $merchantKey;
    protected $merchantSalt;
    protected $baseUrl;
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->merchantId = config('services.paytr.merchant_id');
        $this->merchantKey = config('services.paytr.merchant_key');
        $this->merchantSalt = config('services.paytr.merchant_salt');
        $this->baseUrl = config('services.paytr.base_url', 'https://www.paytr.com/odeme/api/get-token');
        $this->systemLog = $systemLog;
    }

    /**
     * Create payment form for order
     */
    public function createPaymentForm(Order $order, User $user, string $userIp): array
    {
        try {
            // Create payment transaction
            $transaction = PaymentTransaction::createForOrder($order, PaymentTransaction::METHOD_CREDIT_CARD, [
                'transaction_id' => $this->generateMerchantOid(),
            ]);

            // Prepare basket items
            $basket = $this->prepareBasket($order);

            // Calculate hash
            $hashStr = $this->generateHashStr(
                $transaction->transaction_id,
                $order->grand_total,
                $basket,
                $user,
                $userIp
            );

            // Prepare payment data
            $paymentData = [
                'merchant_id' => $this->merchantId,
                'user_ip' => $userIp,
                'merchant_oid' => $transaction->transaction_id,
                'email' => $user->email,
                'payment_amount' => $this->formatAmount($order->grand_total),
                'paytr_token' => $hashStr,
                'user_basket' => $basket,
                'debug_on' => config('app.debug') ? 1 : 0,
                'no_installment' => 0,
                'max_installment' => 12,
                'user_name' => $user->name,
                'user_address' => $order->billing_address,
                'user_phone' => $user->phone,
                'merchant_ok_url' => route('payment.success'),
                'merchant_fail_url' => route('payment.fail'),
                'timeout_limit' => 30,
                'currency' => 'TL',
                'test_mode' => config('services.paytr.test_mode', 0),
            ];

            // Get token from PayTR
            $response = Http::asForm()->post($this->baseUrl, $paymentData);

            if (!$response->successful()) {
                throw new \Exception('PayTR API isteği başarısız: ' . $response->status());
            }

            $result = $response->json();

            if ($result['status'] !== 'success') {
                throw new \Exception('PayTR token alınamadı: ' . ($result['reason'] ?? 'Bilinmeyen hata'));
            }

            // Update transaction with token
            $transaction->update([
                'gateway_response' => [
                    'token' => $result['token'],
                    'created_at' => now()->toIso8601String(),
                ],
            ]);

            $this->systemLog->info('payment', 'PayTR payment form created', [
                'order_id' => $order->id,
                'transaction_id' => $transaction->id,
                'merchant_oid' => $transaction->transaction_id,
            ]);

            return [
                'success' => true,
                'token' => $result['token'],
                'merchant_oid' => $transaction->transaction_id,
                'iframe_url' => 'https://www.paytr.com/odeme/guvenli/' . $result['token'],
            ];

        } catch (\Exception $e) {
            $this->systemLog->error('payment', 'PayTR payment form creation failed', [
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
     * Handle payment callback from PayTR
     */
    public function handleCallback(array $data): bool
    {
        try {
            // Validate callback hash
            if (!$this->validateCallbackHash($data)) {
                $this->systemLog->warning('payment', 'Invalid PayTR callback hash', $data);
                return false;
            }

            // Find transaction
            $transaction = PaymentTransaction::where('transaction_id', $data['merchant_oid'])->first();

            if (!$transaction) {
                $this->systemLog->error('payment', 'PayTR transaction not found', [
                    'merchant_oid' => $data['merchant_oid'],
                ]);
                return false;
            }

            // Update transaction based on status
            if ($data['status'] === 'success') {
                return $this->handleSuccessfulPayment($transaction, $data);
            } else {
                return $this->handleFailedPayment($transaction, $data);
            }

        } catch (\Exception $e) {
            $this->systemLog->error('payment', 'PayTR callback processing failed', [
                'data' => $data,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }

    /**
     * Handle successful payment
     */
    protected function handleSuccessfulPayment(PaymentTransaction $transaction, array $data): bool
    {
        // Update transaction
        $transaction->markAsSuccessful([
            'paytr_data' => $data,
            'completed_at' => now()->toIso8601String(),
        ]);

        // Update card info if available
        if (isset($data['masked_pan'])) {
            $transaction->update([
                'masked_card_number' => $data['masked_pan'],
                'card_holder_name' => $data['cardholder_name'] ?? null,
            ]);
        }

        // Update order
        $order = $transaction->order;
        $order->update(['payment_status' => Order::PAYMENT_PAID]);
        $order->confirm();

        // Create order log
        OrderLog::logPayment($order, $transaction->amount, $transaction->payment_method);

        // Send notification
        Notification::createPaymentNotification(
            $order->user,
            Notification::TYPE_PAYMENT_RECEIVED,
            $transaction->amount
        );

        $this->systemLog->info('payment', 'PayTR payment successful', [
            'transaction_id' => $transaction->id,
            'order_id' => $order->id,
            'amount' => $transaction->amount,
        ]);

        return true;
    }

    /**
     * Handle failed payment
     */
    protected function handleFailedPayment(PaymentTransaction $transaction, array $data): bool
    {
        // Update transaction
        $transaction->markAsFailed([
            'paytr_data' => $data,
            'failed_reason' => $data['failed_reason_msg'] ?? 'Bilinmeyen hata',
            'failed_at' => now()->toIso8601String(),
        ]);

        // Send notification
        Notification::createPaymentNotification(
            $transaction->order->user,
            Notification::TYPE_PAYMENT_FAILED,
            $transaction->amount,
            'Ödeme işleminiz başarısız oldu: ' . ($data['failed_reason_msg'] ?? 'Bilinmeyen hata')
        );

        $this->systemLog->warning('payment', 'PayTR payment failed', [
            'transaction_id' => $transaction->id,
            'order_id' => $transaction->order_id,
            'reason' => $data['failed_reason_msg'] ?? 'Unknown',
        ]);

        return true;
    }

    /**
     * Prepare basket for PayTR
     */
    protected function prepareBasket(Order $order): string
    {
        $basket = [];

        foreach ($order->items as $item) {
            $basket[] = [
                $item->product->name,
                number_format($item->unit_price, 2, '.', ''),
                $item->quantity,
            ];
        }

        // Add shipping if any
        if ($order->shipping_cost > 0) {
            $basket[] = [
                'Kargo Ücreti',
                number_format($order->shipping_cost, 2, '.', ''),
                1,
            ];
        }

        return base64_encode(json_encode($basket));
    }

    /**
     * Generate hash string for PayTR
     */
    protected function generateHashStr(string $merchantOid, float $amount, string $basket, User $user, string $userIp): string
    {
        $hashStr = $this->merchantId .
            $userIp .
            $merchantOid .
            $user->email .
            $this->formatAmount($amount) .
            $basket .
            0 . // no_installment
            12 . // max_installment
            'TL' .
            (config('services.paytr.test_mode', 0) ? '1' : '0');

        $token = base64_encode(hash_hmac('sha256', $hashStr . $this->merchantSalt, $this->merchantKey, true));

        return $token;
    }

    /**
     * Validate callback hash
     */
    protected function validateCallbackHash(array $data): bool
    {
        $hashStr = $data['merchant_oid'] .
            $this->merchantSalt .
            $data['status'] .
            $data['total_amount'];

        $token = base64_encode(hash_hmac('sha256', $hashStr, $this->merchantKey, true));

        return $token === $data['hash'];
    }

    /**
     * Generate unique merchant OID
     */
    protected function generateMerchantOid(): string
    {
        return 'PAY' . date('YmdHis') . Str::random(6);
    }

    /**
     * Format amount for PayTR (multiply by 100)
     */
    protected function formatAmount(float $amount): int
    {
        return (int) ($amount * 100);
    }

    /**
     * Refund payment
     */
    public function refundPayment(PaymentTransaction $transaction, float $amount = null, string $reason = ''): bool
    {
        try {
            if (!$transaction->isSuccessful()) {
                throw new \Exception('Sadece başarılı ödemeler iade edilebilir');
            }

            $refundAmount = $amount ?? $transaction->amount;

            if ($refundAmount > $transaction->amount) {
                throw new \Exception('İade tutarı ödeme tutarını aşamaz');
            }

            // PayTR refund API call would go here
            // For now, we'll simulate it

            $this->systemLog->info('payment', 'PayTR refund initiated', [
                'transaction_id' => $transaction->id,
                'refund_amount' => $refundAmount,
                'reason' => $reason,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->systemLog->error('payment', 'PayTR refund failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ], $e);

            return false;
        }
    }
}
