<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

class EmailVerificationNotification extends VerifyEmail
{
    protected function buildMailMessage($url)
    {
        return (new MailMessage)
            ->subject(Lang::get('messages.mail_email_verify_subject'))
            ->line(Lang::get('messages.mail_email_verify_text'))
            ->action(Lang::get('messages.mail_email_verify_btn'), $url)
            ->line(Lang::get('messages.mail_email_not_register'));
    }
}
