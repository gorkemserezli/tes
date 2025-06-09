<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\ArasCargoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateShipmentStatus implements ShouldQueue
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
    public function handle(ArasCargoService $arasService): void
    {
        // Get active shipments that need tracking updates
        $activeShipments = Shipment::whereNotIn('status', [
            Shipment::STATUS_DELIVERED,
            Shipment::STATUS_RETURNED,
            Shipment::STATUS_LOST
        ])
            ->where('carrier', 'aras')
            ->whereNotNull('tracking_number')
            ->where(function ($query) {
                $query->whereNull('last_update_at')
                    ->orWhere('last_update_at', '<', now()->subHours(4));
            })
            ->get();

        foreach ($activeShipments as $shipment) {
            try {
                // Get tracking info from Aras
                $trackingResult = $arasService->trackShipment($shipment->tracking_number);

                if ($trackingResult['success']) {
                    Log::info('Shipment status updated', [
                        'shipment_id' => $shipment->id,
                        'tracking_number' => $shipment->tracking_number,
                        'new_status' => $trackingResult['status'] ?? 'unknown',
                    ]);
                } else {
                    Log::warning('Failed to update shipment status', [
                        'shipment_id' => $shipment->id,
                        'tracking_number' => $shipment->tracking_number,
                        'error' => $trackingResult['error'] ?? 'Unknown error',
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('Error updating shipment status', [
                    'shipment_id' => $shipment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Add delay to avoid rate limiting
            sleep(2);
        }

        if ($activeShipments->count() > 0) {
            Log::info('Updated tracking status for ' . $activeShipments->count() . ' shipments.');
        }
    }
}
