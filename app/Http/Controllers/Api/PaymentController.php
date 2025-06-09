<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Services\PayTRService;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    protected PayTRService $paytrService;
    protected SystemLogService $systemLog;

    public function __construct(PayTRService $paytrService, SystemLogService $systemLog)
    {
        $this->paytrService = $paytrService;
        $this->systemLog = $systemLog;
    }

    /**
     * Process payment for order
     */
    public function process(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'payment_method' => 'required|in:credit_card,bank_transfer,balance',
        ]);

        $order = Order::where('id', $request->order_id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        // Check if order is payable
        if ($order->payment_status !== Order::PAYMENT_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Bu sipariş için ödeme alınamaz.',
            ], 400);
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            return response()->json([
                'success' => false,
                'message' => 'İptal edilmiş sipariş için ödeme yapılamaz.',
            ], 400);
        }

        // Process based on payment method
        switch ($request->payment_method) {
            case 'credit_card':
                return $this->processCreditCardPayment($order, $request);

            case 'bank_transfer':
                return $this->processBankTransferPayment($order, $request);

            case 'balance':
                return $this->processBalancePayment($order);

            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Geçersiz ödeme yöntemi.',
                ], 400);
        }
    }

    /**
     * Process credit card payment
     */
    protected function processCreditCardPayment(Order $order, Request $request)
    {
        try {
            // Get PayTR payment form
            $result = $this->paytrService->createPaymentForm(
                $order,
                auth()->user(),
                $request->ip()
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ödeme formu oluşturulamadı: ' . $result['error'],
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_token' => $result['token'],
                    'merchant_oid' => $result['merchant_oid'],
                    'iframe_url' => $result['iframe_url'],
                ],
            ]);

        } catch (\Exception $e) {
            $this->systemLog->error('payment', 'Credit card payment failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Ödeme işlemi başlatılamadı.',
            ], 500);
        }
    }

    /**
     * Process bank transfer payment
     */
    protected function processBankTransferPayment(Order $order, Request $request)
    {
        $request->validate([
            'bank_name' => 'required|string|max:100',
            'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        DB::beginTransaction();

        try {
            // Upload receipt
            $receiptPath = $request->file('receipt')->store('receipts/' . date('Y/m'), 'public');

            // Create payment transaction
            $transaction = PaymentTransaction::createForOrder($order, PaymentTransaction::METHOD_BANK_TRANSFER);
            $transaction->processBankTransfer($request->bank_name, $receiptPath);

            // Update order
            $order->update([
                'payment_method' => 'bank_transfer',
                'internal_notes' => $order->internal_notes . "\n[" . now()->format('d.m.Y H:i') . "] Havale dekontu yüklendi. Onay bekleniyor.",
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Havale dekontunuz alındı. Ödemeniz onaylandıktan sonra siparişiniz hazırlanacaktır.',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'receipt_url' => asset('storage/' . $receiptPath),
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('payment', 'Bank transfer payment failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Dekont yüklenirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Process balance payment
     */
    protected function processBalancePayment(Order $order)
    {
        $user = auth()->user();
        $company = $user->company;

        // Check if user can use balance payment
        if (!$company) {
            return response()->json([
                'success' => false,
                'message' => 'Cari bakiye ödemesi için şirket kaydınız bulunmuyor.',
            ], 400);
        }

        // Check balance
        if (!$company->hasBalance($order->grand_total)) {
            return response()->json([
                'success' => false,
                'message' => 'Yetersiz bakiye. Mevcut bakiyeniz: ' . number_format($company->balance, 2, ',', '.') . ' ₺',
                'data' => [
                    'current_balance' => $company->balance,
                    'required_amount' => $order->grand_total,
                    'shortage' => $order->grand_total - $company->balance,
                ],
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Create payment transaction
            $transaction = PaymentTransaction::createForOrder($order, PaymentTransaction::METHOD_BALANCE);

            // Process balance payment
            if (!$transaction->processBalancePayment()) {
                throw new \Exception('Bakiye ödemesi işlenemedi');
            }

            // Update order payment method
            $order->update(['payment_method' => 'balance']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Ödemeniz başarıyla alındı. Siparişiniz hazırlanacaktır.',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'new_balance' => $company->fresh()->balance,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('payment', 'Balance payment failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Ödeme işlemi sırasında bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Payment success callback
     */
    public function success(Request $request)
    {
        $merchantOid = $request->get('merchant_oid');

        if (!$merchantOid) {
            return redirect()->route('orders.index')->with('error', 'Geçersiz ödeme referansı.');
        }

        $transaction = PaymentTransaction::where('transaction_id', $merchantOid)->first();

        if (!$transaction) {
            return redirect()->route('orders.index')->with('error', 'Ödeme kaydı bulunamadı.');
        }

        // Check if payment is already successful
        if ($transaction->isSuccessful()) {
            return redirect()->route('orders.show', $transaction->order->order_number)
                ->with('success', 'Ödemeniz başarıyla alındı.');
        }

        // Payment might still be processing
        return redirect()->route('orders.show', $transaction->order->order_number)
            ->with('info', 'Ödemeniz işleniyor. Lütfen bekleyin.');
    }

    /**
     * Payment fail callback
     */
    public function fail(Request $request)
    {
        $merchantOid = $request->get('merchant_oid');

        if ($merchantOid) {
            $transaction = PaymentTransaction::where('transaction_id', $merchantOid)->first();

            if ($transaction) {
                return redirect()->route('orders.show', $transaction->order->order_number)
                    ->with('error', 'Ödeme işlemi başarısız oldu. Lütfen tekrar deneyin.');
            }
        }

        return redirect()->route('cart.index')
            ->with('error', 'Ödeme işlemi başarısız oldu.');
    }

    /**
     * Check payment status
     */
    public function checkStatus(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:payment_transactions,id',
        ]);

        $transaction = PaymentTransaction::where('id', $request->transaction_id)
            ->whereHas('order', function ($query) {
                $query->where('user_id', auth()->id());
            })
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $transaction->status,
                'status_label' => $transaction->status_label,
                'is_successful' => $transaction->isSuccessful(),
                'processed_at' => $transaction->processed_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    /**
     * Get payment methods for user
     */
    public function getMethods(Request $request)
    {
        $user = auth()->user();
        $company = $user->company;

        $methods = [
            [
                'method' => 'credit_card',
                'label' => 'Kredi Kartı',
                'icon' => 'credit-card',
                'available' => true,
                'description' => 'Kredi kartı ile güvenli ödeme',
            ],
            [
                'method' => 'bank_transfer',
                'label' => 'Havale/EFT',
                'icon' => 'building-bank',
                'available' => true,
                'description' => 'Banka havalesi ile ödeme',
            ],
            [
                'method' => 'balance',
                'label' => 'Cari Bakiye',
                'icon' => 'wallet',
                'available' => $company && $company->balance > 0,
                'description' => 'Mevcut bakiye: ' . ($company ? number_format($company->balance, 2, ',', '.') . ' ₺' : '0,00 ₺'),
                'balance' => $company?->balance ?? 0,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
    }
}
