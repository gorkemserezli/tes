<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $newPassword;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $newPassword)
    {
        $this->newPassword = $newPassword;
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
            ->subject('Şifreniz Sıfırlandı')
            ->greeting('Merhaba ' . $notifiable->name . ',')
            ->line('Şifreniz yönetici tarafından sıfırlandı.')
            ->line('Yeni şifreniz:')
            ->line('')
            ->line('**' . $this->newPassword . '**')
            ->line('')
            ->line('Güvenliğiniz için lütfen ilk girişinizde şifrenizi değiştirin.')
            ->action('Giriş Yap', url('/login'))
            ->line('Bu şifreyi kimseyle paylaşmayın ve güvenli bir yerde saklayın.')
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
            'type' => 'password_reset',
            'title' => 'Şifreniz Sıfırlandı',
            'message' => 'Şifreniz yönetici tarafından sıfırlandı. Lütfen e-postanızı kontrol edin.',
        ];
    }
}
