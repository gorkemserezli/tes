<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'carrier' => $this->carrier,
            'carrier_label' => $this->carrier_label,
            'tracking_number' => $this->tracking_number,
            'tracking_url' => $this->tracking_url,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'status_description' => $this->status_description,
            'weight' => $this->weight,
            'desi' => $this->desi,
            'barcode_pdf_url' => $this->barcode_pdf_url,
            'is_dropshipping' => $this->is_dropshipping,
            'is_delivered' => $this->isDelivered(),
            'is_trackable' => $this->isTrackable(),
            'estimated_delivery' => $this->estimated_delivery,
            'shipped_at' => $this->shipped_at?->format('d.m.Y H:i'),
            'delivered_at' => $this->delivered_at?->format('d.m.Y H:i'),
            'delivery_signature' => $this->delivery_signature,
            'last_update_at' => $this->last_update_at?->format('d.m.Y H:i'),
        ];
    }
}
