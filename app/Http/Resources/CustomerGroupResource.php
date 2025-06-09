<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerGroupResource extends JsonResource
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
            'name' => $this->name,
            'discount_percentage' => $this->discount_percentage,
            'formatted_discount' => $this->formatted_discount,
            'is_active' => $this->is_active,
            'user_count' => $this->when(isset($this->user_count), $this->user_count),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
        ];
    }
}
