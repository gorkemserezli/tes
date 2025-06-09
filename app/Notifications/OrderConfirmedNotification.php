<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmedNotification extends Notification implements ShouldQueue
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
            ->subject('Siparişiniz Onaylandı - ' . $this->order->order_number)
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('Siparişiniz başarıyla onaylandı ve hazırlanmaya başlandı.')
            ->line('**Sipariş Detayları:**')
            ->line('Sipariş No: ' . $this->order->order_number)
            ->line('Sipariş Tarihi: ' . $this->order->created_at->format('d.m.Y H:i'))
            ->line('Toplam Tutar: ' . number_format($this->order->grand_total, 2, ',', '.') . ' ₺')
            ->line('Ödeme Yöntemi: ' . $this->order->payment_method_label);

        if ($this->order->items->count() > 0) {
            $mail->line('');
            $mail->line('**Sipariş Kalemleri:**');

            foreach ($this->order->items->take(5) as $item) {
                $mail->line('• ' . $item->product->name . ' x ' . $item->quantity . ' = ' .
                    number_format($item->total_price, 2, ',', '.') . ' ₺');
            }

            if ($this->order->items->count() > 5) {
                $mail->line('... ve ' . ($this->order->items->count() - 5) . ' ürün daha');
            }
        }

        $mail->line('');
        $mail->line('Siparişinizin durumunu aşağıdaki bağlantıdan takip edebilirsiniz:');
        $mail->action('Siparişimi Görüntüle', url('/orders/' . $this->order->order_number));
        $mail->line('Bizi tercih ettiğiniz için teşekkür ederiz.');

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
            'type' => 'order_confirmed',
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'title' => 'Siparişiniz Onaylandı',
            'message' => 'Siparişiniz onaylandı ve hazırlanmaya başlandı. Sipariş No: ' . $this->order->order_number,
            'action_url' => '/orders/' . $this->order->order_number,
        ];
    }
}
