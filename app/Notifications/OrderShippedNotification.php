<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
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
            ->subject('Siparişiniz Kargoya Verildi - ' . $this->order->order_number)
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('Siparişiniz kargoya verildi ve yola çıktı!')
            ->line('**Kargo Bilgileri:**')
            ->line('Sipariş No: ' . $this->order->order_number);

        if ($this->order->shipment) {
            $mail->line('Kargo Firması: ' . $this->order->shipment->carrier_label)
                ->line('Takip No: ' . $this->order->shipment->tracking_number);

            if ($this->order->shipment->estimated_delivery) {
                $mail->line('Tahmini Teslimat: ' . $this->order->shipment->estimated_delivery);
            }

            if ($this->order->shipment->tracking_url) {
                $mail->line('');
                $mail->action('Kargo Takibi', $this->order->shipment->tracking_url);
            }
        }

        $mail->line('');
        $mail->line('**Teslimat Adresi:**');
        $mail->line($this->order->full_shipping_address);

        $mail->line('');
        $mail->line('Kargonuz size ulaştığında, teslim alan kişinin kimlik bilgilerini kontrol etmeyi unutmayın.');
        $mail->line('Siparişinizle ilgili detaylı bilgiye aşağıdaki bağlantıdan ulaşabilirsiniz:');
        $mail->action('Siparişimi Görüntüle', url('/orders/' . $this->order->order_number));

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
            'type' => 'order_shipped',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'tracking_number' => $this->order->shipment?->tracking_number,
            'title' => 'Siparişiniz Kargoda',
            'message' => 'Siparişiniz kargoya verildi. Takip No: ' . $this->order->shipment?->tracking_number,
            'action_url' => '/orders/' . $this->order->order_number,
        ];
    }
}
