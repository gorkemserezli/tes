<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingDocumentResource extends JsonResource
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
            'document_type' => $this->document_type,
            'document_type_label' => $this->document_type_label,
            'document_type_icon' => $this->document_type_icon,
            'file_name' => $this->file_name,
            'file_url' => $this->file_url,
            'file_size' => $this->file_size,
            'formatted_file_size' => $this->formatted_file_size,
            'file_extension' => $this->file_extension,
            'mime_type' => $this->mime_type,
            'is_pdf' => $this->isPdf(),
            'is_image' => $this->isImage(),
            'uploaded_by' => $this->whenLoaded('uploadedBy', function () {
                return [
                    'id' => $this->uploadedBy->id,
                    'name' => $this->uploadedBy->name,
                ];
            }),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
        ];
    }
}
