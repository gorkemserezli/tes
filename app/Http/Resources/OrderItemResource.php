<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
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
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'slug' => $this->product->slug,
                'image' => $this->product->primaryImage?->url,
            ],
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'vat_rate' => $this->vat_rate,
            'discount_amount' => $this->discount_amount,
            'subtotal' => $this->subtotal,
            'vat_amount' => $this->vat_amount,
            'total_price' => $this->total_price,
            'formatted_unit_price' => $this->formatted_unit_price,
            'formatted_total_price' => $this->formatted_total_price,
        ];
    }
}
