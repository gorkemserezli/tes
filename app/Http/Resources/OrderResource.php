<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'order_number' => $this->order_number,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'payment_status' => $this->payment_status,
            'payment_status_label' => $this->payment_status_label,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method_label,
            'order_type' => $this->order_type,
            'delivery_type' => $this->delivery_type,
            'item_count' => $this->item_count,
            'product_count' => $this->product_count,
            'subtotal' => $this->subtotal,
            'vat_total' => $this->vat_total,
            'shipping_cost' => $this->shipping_cost,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'formatted_grand_total' => number_format($this->grand_total, 2, ',', '.') . ' ₺',
            'notes' => $this->notes,
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_shipped' => $this->canBeShipped(),
            'has_shipment' => $this->shipment !== null,
            'tracking_number' => $this->shipment?->tracking_number,
            'created_at' => $this->created_at->format('d.m.Y H:i'),
            'shipped_at' => $this->shipped_at?->format('d.m.Y'),
            'delivered_at' => $this->delivered_at?->format('d.m.Y'),
        ];
    }
}
