<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderLogResource extends JsonResource
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
            'action' => $this->action,
            'action_label' => $this->action_label,
            'action_icon' => $this->action_icon,
            'action_color' => $this->action_color,
            'description' => $this->description,
            'formatted_description' => $this->formatted_description,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'metadata' => $this->metadata,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
        ];
    }
}
