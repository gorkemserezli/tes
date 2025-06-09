<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $code;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Giriş Doğrulama Kodu')
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('Hesabınıza giriş yapmak için doğrulama kodunuz:')
            ->line('')
            ->line('**' . $this->code . '**')
            ->line('')
            ->line('Bu kod ' . config('auth.two_factor_expire_minutes', 10) . ' dakika boyunca geçerlidir.')
            ->line('Eğer bu giriş denemesini siz yapmadıysanız, lütfen bu e-postayı dikkate almayın.')
            ->line('Güvenliğiniz için doğrulama kodunu kimseyle paylaşmayın.')
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
            'type' => 'two_factor',
            'code' => $this->code,
        ];
    }
}
