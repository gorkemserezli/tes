<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceTransactionResource extends JsonResource
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
            'type' => $this->type,
            'type_label' => $this->type_label,
            'type_color' => $this->type_color,
            'amount' => $this->amount,
            'formatted_amount' => $this->formatted_amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'description' => $this->description,
            'adjustment_reason' => $this->when($this->type === 'manual_adjustment', $this->adjustment_reason),
            'is_income' => $this->isIncome(),
            'is_expense' => $this->isExpense(),
            'is_manual' => $this->isManual(),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
        ];
    }
}
