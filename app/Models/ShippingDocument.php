<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ShippingDocument extends Model
{
    use HasFactory;

    /**
     * Document types
     */
    const TYPE_BARCODE = 'barcode';
    const TYPE_WAYBILL = 'waybill';
    const TYPE_INVOICE = 'invoice';
    const TYPE_OTHER = 'other';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'shipment_id',
        'document_type',
        'file_path',
        'file_name',
        'file_size',
        'uploaded_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'file_size' => 'integer',
    ];

    /**
     * The "booting" method of the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Delete physical file when model is deleted
        static::deleting(function ($document) {
            if (Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }
        });
    }

    /**
     * Get the order that owns the document.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the shipment that owns the document.
     */
    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * Get the user who uploaded the document.
     */
    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get document type label.
     */
    public function getDocumentTypeLabelAttribute(): string
    {
        $labels = [
            self::TYPE_BARCODE => 'Kargo Barkodu',
            self::TYPE_WAYBILL => 'İrsaliye',
            self::TYPE_INVOICE => 'Fatura',
            self::TYPE_OTHER => 'Diğer',
        ];

        return $labels[$this->document_type] ?? $this->document_type;
    }

    /**
     * Get document type icon.
     */
    public function getDocumentTypeIconAttribute(): string
    {
        $icons = [
            self::TYPE_BARCODE => 'qrcode',
            self::TYPE_WAYBILL => 'document-text',
            self::TYPE_INVOICE => 'document-report',
            self::TYPE_OTHER => 'document',
        ];

        return $icons[$this->document_type] ?? 'document';
    }

    /**
     * Get file URL.
     */
    public function getFileUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    /**
     * Get formatted file size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }

    /**
     * Get file extension.
     */
    public function getFileExtensionAttribute(): string
    {
        return pathinfo($this->file_name, PATHINFO_EXTENSION);
    }

    /**
     * Check if document is PDF.
     */
    public function isPdf(): bool
    {
        return strtolower($this->file_extension) === 'pdf';
    }

    /**
     * Check if document is image.
     */
    public function isImage(): bool
    {
        return in_array(strtolower($this->file_extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    /**
     * Get mime type.
     */
    public function getMimeTypeAttribute(): string
    {
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $extension = strtolower($this->file_extension);

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Create document from upload.
     */
    public static function createFromUpload(
        Order $order,
        \Illuminate\Http\UploadedFile $file,
        string $documentType,
        ?Shipment $shipment = null
    ): self {
        $fileName = $file->getClientOriginalName();
        $filePath = $file->store('shipping/' . date('Y/m'), 'public');

        return static::create([
            'order_id' => $order->id,
            'shipment_id' => $shipment?->id,
            'document_type' => $documentType,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $file->getSize(),
            'uploaded_by' => auth()->id(),
        ]);
    }
}
