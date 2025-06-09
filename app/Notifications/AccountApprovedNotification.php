<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        //
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
        return (new MailMessage)
            ->subject('Hesabınız Onaylandı - Alışverişe Başlayabilirsiniz!')
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('Harika haber! Şirket hesabınız başarıyla onaylandı.')
            ->line('Artık B2B platformumuzda alışveriş yapabilirsiniz.')
            ->line('**Hesabınızla yapabilecekleriniz:**')
            ->line('• Ürünleri sepete ekleyebilir ve sipariş verebilirsiniz')
            ->line('• Size özel fiyatları görüntüleyebilirsiniz')
            ->line('• Cari bakiye yükleyebilir ve kullanabilirsiniz')
            ->line('• Fatura ve kargo bilgilerinizi takip edebilirsiniz')
            ->line('• Geçmiş siparişlerinizi görüntüleyebilirsiniz')
            ->action('Alışverişe Başla', url('/products'))
            ->line('Size özel kampanya ve fırsatlardan haberdar olmak için bildirimlerinizi açık tutmayı unutmayın.')
            ->line('Keyifli alışverişler dileriz!')
            ->salutation('Saygılarımızla,');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_approved',
            'title' => 'Hesabınız Onaylandı',
            'message' => 'Tebrikler! Hesabınız onaylandı ve artık alışveriş yapabilirsiniz.',
            'action_url' => '/products',
        ];
    }
}
