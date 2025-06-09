<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PayTRService;
use App\Services\ArasCargoService;
use App\Services\SystemLogService;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->systemLog = $systemLog;
    }

    /**
     * Handle PayTR webhook
     */
    public function paytr(Request $request, PayTRService $paytrService)
    {
        // Log incoming webhook
        $this->systemLog->info('payment', 'PayTR webhook received', [
            'data' => $request->all(),
            'ip' => $request->ip(),
        ]);

        try {
            // PayTR sends POST data
            $data = $request->all();

            // Required fields
            $requiredFields = ['merchant_oid', 'status', 'total_amount', 'hash'];

            foreach ($requiredFields as $field) {
                if (!isset($data[$field])) {
                    $this->systemLog->error('payment', 'PayTR webhook missing required field', [
                        'field' => $field,
                        'data' => $data,
                    ]);

                    return response('INVALID_REQUEST', 400);
                }
            }

            // Process callback
            $result = $paytrService->handleCallback($data);

            if ($result) {
                // PayTR expects 'OK' response for successful processing
                return response('OK', 200);
            } else {
                return response('FAILED', 400);
            }

        } catch (\Exception $e) {
            $this->systemLog->error('payment', 'PayTR webhook processing failed', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ], $e);

            return response('ERROR', 500);
        }
    }

    /**
     * Handle Aras Cargo webhook
     */
    public function arasCargo(Request $request, ArasCargoService $arasService)
    {
        // Log incoming webhook
        $this->systemLog->info('shipping', 'Aras Cargo webhook received', [
            'data' => $request->all(),
            'ip' => $request->ip(),
        ]);

        try {
            // Aras Cargo sends JSON data
            $data = $request->json()->all();

            // Validate signature if provided
            $signature = $request->header('X-Aras-Signature');

            if ($signature && !$arasService->validateWebhookSignature($data, $signature)) {
                $this->systemLog->warning('shipping', 'Invalid Aras Cargo webhook signature', [
                    'signature' => $signature,
                    'data' => $data,
                ]);

                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Process webhook based on type
            $type = $data['event_type'] ?? null;

            switch ($type) {
                case 'shipment.status_changed':
                    $result = $arasService->handleStatusUpdate($data);
                    break;

                case 'shipment.delivered':
                    $result = $arasService->handleDelivery($data);
                    break;

                case 'shipment.returned':
                    $result = $arasService->handleReturn($data);
                    break;

                default:
                    $this->systemLog->warning('shipping', 'Unknown Aras Cargo webhook type', [
                        'type' => $type,
                        'data' => $data,
                    ]);

                    return response()->json(['error' => 'Unknown event type'], 400);
            }

            if ($result) {
                return response()->json(['success' => true], 200);
            } else {
                return response()->json(['error' => 'Processing failed'], 400);
            }

        } catch (\Exception $e) {
            $this->systemLog->error('shipping', 'Aras Cargo webhook processing failed', [
                'error' => $e->getMessage(),
                'data' => $request->all(),
            ], $e);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Test webhook endpoint (only in development)
     */
    public function test(Request $request)
    {
        if (!app()->environment('local', 'development')) {
            abort(404);
        }

        $this->systemLog->debug('webhook', 'Test webhook received', [
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'data' => $request->all(),
            'raw' => $request->getContent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
