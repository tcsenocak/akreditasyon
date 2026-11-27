<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Başvuru sürecindeki durum değişikliklerinin e-posta bildirimi (öğrenciye ya da komisyona).
 */
class BasvuruBildirimi extends Notification
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
        public string $url,
        public string $actionText = 'Sisteme git',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject.' — Muafiyet ve İntibak')
            ->greeting('Merhaba,');
        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action($this->actionText, $this->url)
            ->salutation('Atatürk Üniversitesi Mühendislik Fakültesi Muafiyet ve İntibak Komisyonu');
    }
}
