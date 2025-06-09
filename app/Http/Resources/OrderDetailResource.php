<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderDetailResource extends JsonResource
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

            // Address information
            'shipping_address' => $this->shipping_address,
            'shipping_contact_name' => $this->shipping_contact_name,
            'shipping_contact_phone' => $this->shipping_contact_phone,
            'billing_address' => $this->billing_address,
            'billing_contact_name' => $this->billing_contact_name,
            'billing_contact_phone' => $this->billing_contact_phone,
            'use_different_shipping' => $this->use_different_shipping,

            // Pricing
            'subtotal' => $this->subtotal,
            'vat_total' => $this->vat_total,
            'shipping_cost' => $this->shipping_cost,
            'discount_total' => $this->discount_total,
            'grand_total' => $this->grand_total,
            'formatted_subtotal' => number_format($this->subtotal, 2, ',', '.') . ' ₺',
            'formatted_vat_total' => number_format($this->vat_total, 2, ',', '.') . ' ₺',
            'formatted_shipping_cost' => number_format($this->shipping_cost, 2, ',', '.') . ' ₺',
            'formatted_discount_total' => number_format($this->discount_total, 2, ',', '.') . ' ₺',
            'formatted_grand_total' => number_format($this->grand_total, 2, ',', '.') . ' ₺',

            // Notes
            'notes' => $this->notes,
            'internal_notes' => $this->when(auth()->user()?->isAdmin(), $this->internal_notes),

            // Flags
            'is_dropshipping' => $this->is_dropshipping,
            'can_be_cancelled' => $this->canBeCancelled(),
            'can_be_shipped' => $this->canBeShipped(),
            'is_editable' => $this->isEditable(),

            // Relations
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'user' => new UserResource($this->whenLoaded('user')),
            'company' => new CompanyResource($this->when($this->relationLoaded('user') && $this->user->relationLoaded('company'), $this->user?->company)),
            'payments' => PaymentTransactionResource::collection($this->whenLoaded('payments')),
            'shipment' => new ShipmentResource($this->whenLoaded('shipment')),
            'shipping_documents' => ShippingDocumentResource::collection($this->whenLoaded('shippingDocuments')),
            'logs' => OrderLogResource::collection($this->whenLoaded('logs')),

            // Dates
            'created_at' => $this->created_at->format('d.m.Y H:i'),
            'updated_at' => $this->updated_at->format('d.m.Y H:i'),
            'shipped_at' => $this->shipped_at?->format('d.m.Y H:i'),
            'delivered_at' => $this->delivered_at?->format('d.m.Y H:i'),
        ];
    }
}
