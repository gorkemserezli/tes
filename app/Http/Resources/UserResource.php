<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'is_admin' => $this->is_admin,
            'is_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->format('d.m.Y H:i'),
            'last_login_at' => $this->last_login_at?->format('d.m.Y H:i'),
            'created_at' => $this->created_at->format('d.m.Y H:i'),

            // Relations
            'company' => new CompanyResource($this->whenLoaded('company')),
            'groups' => CustomerGroupResource::collection($this->whenLoaded('groups')),

            // Computed attributes
            'balance' => $this->when($this->relationLoaded('company'), $this->company?->balance),
            'credit_limit' => $this->when($this->relationLoaded('company'), $this->company?->credit_limit),
            'remaining_credit' => $this->when($this->relationLoaded('company'), $this->company?->remaining_credit),
            'discount_percentage' => $this->when($this->relationLoaded('groups'), $this->getDiscountPercentage()),

            // Permissions
            'permissions' => [
                'can_order' => $this->isActive() && $this->isApproved(),
                'can_use_balance' => $this->company && $this->company->balance > 0,
                'is_approved' => $this->isApproved(),
            ],
        ];
    }
}
