<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTransactionResource extends JsonResource
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
            'transaction_id' => $this->transaction_id,
            'payment_method' => $this->payment_method,
            'payment_method_label' => $this->payment_method_label,
            'amount' => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'payment_display_info' => $this->payment_display_info,
            'bank_name' => $this->when($this->payment_method === 'bank_transfer', $this->bank_name),
            'receipt_url' => $this->when($this->payment_method === 'bank_transfer', $this->receipt_url),
            'processed_at' => $this->processed_at?->format('d.m.Y H:i'),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
        ];
    }
}
