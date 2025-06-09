<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $products;

    /**
     * Create a new notification instance.
     */
    public function __construct($products)
    {
        $this->products = $products;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Düşük Stok Uyarısı - Acil Kontrol Gerekiyor')
            ->greeting('Merhaba Yönetici,')
            ->line('Aşağıdaki ürünlerin stok seviyeleri kritik seviyeye düştü:')
            ->line('');

        foreach ($this->products as $product) {
            $mail->line('**' . $product->name . '** (SKU: ' . $product->sku . ')')
                ->line('Mevcut Stok: ' . $product->stock_quantity . ' adet')
                ->line('---');
        }

        $mail->line('');
        $mail->line('Stok durumunu kontrol etmek ve sipariş vermek için aşağıdaki bağlantıyı kullanabilirsiniz:');
        $mail->action('Stok Yönetimi', url('/admin/products?filter=low_stock'));
        $mail->line('Müşteri memnuniyeti için stok seviyelerinin düzenli kontrolü önemlidir.');

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'low_stock',
            'title' => 'Düşük Stok Uyarısı',
            'message' => count($this->products) . ' ürünün stok seviyesi kritik seviyede.',
            'products' => $this->products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $product->stock_quantity,
                ];
            }),
            'action_url' => '/admin/products?filter=low_stock',
        ];
    }
}
