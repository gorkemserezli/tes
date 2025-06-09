<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
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
            'company_name' => $this->company_name,
            'tax_number' => $this->tax_number,
            'tax_office' => $this->tax_office,
            'address' => $this->address,
            'city' => $this->city,
            'district' => $this->district,
            'postal_code' => $this->postal_code,
            'full_address' => $this->full_address,
            'is_approved' => $this->is_approved,
            'approved_at' => $this->approved_at?->format('d.m.Y H:i'),
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ];
            }),
            'credit_limit' => $this->credit_limit,
            'remaining_credit' => $this->remaining_credit,
            'balance' => $this->balance,
            'formatted_balance' => number_format($this->balance, 2, ',', '.') . ' ₺',
            'formatted_credit_limit' => number_format($this->credit_limit, 2, ',', '.') . ' ₺',
            'formatted_remaining_credit' => number_format($this->remaining_credit, 2, ',', '.') . ' ₺',
            'created_at' => $this->created_at->format('d.m.Y H:i'),
            'updated_at' => $this->updated_at->format('d.m.Y H:i'),

            // Relations
            'user' => new UserResource($this->whenLoaded('user')),
            'balance_transactions' => BalanceTransactionResource::collection($this->whenLoaded('balanceTransactions')),

            // Computed
            'has_balance' => $this->balance > 0,
            'has_credit' => $this->credit_limit > 0,
            'credit_usage_percentage' => $this->credit_limit > 0
                ? round((($this->credit_limit - $this->remaining_credit) / $this->credit_limit) * 100, 2)
                : 0,
        ];
    }
}
