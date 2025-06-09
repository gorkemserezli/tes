<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
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
            ->subject('B2B E-Ticaret Platformuna Hoş Geldiniz')
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('B2B e-ticaret platformumuza kayıt olduğunuz için teşekkür ederiz.')
            ->line('Hesabınız başarıyla oluşturuldu. Şirket bilgileriniz onaylandıktan sonra alışverişe başlayabilirsiniz.')
            ->line('Onay süreci genellikle 1-2 iş günü içerisinde tamamlanmaktadır.')
            ->line('Bu süre zarfında şu işlemleri yapabilirsiniz:')
            ->line('• Profil bilgilerinizi güncelleyebilirsiniz')
            ->line('• Ürün kataloğumuzu inceleyebilirsiniz')
            ->line('• Fiyat listelerini görüntüleyebilirsiniz')
            ->action('Hesabıma Git', url('/login'))
            ->line('Herhangi bir sorunuz olursa bizimle iletişime geçmekten çekinmeyin.')
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
            'type' => 'welcome',
            'title' => 'Hoş Geldiniz',
            'message' => 'B2B platformumuza kayıt olduğunuz için teşekkür ederiz. Hesabınız onay sürecindedir.',
        ];
    }
}
