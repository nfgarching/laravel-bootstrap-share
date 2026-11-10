<?php

namespace Nfgarching\Share\Support;

use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;

/**
 * Betreff und Text der E-Mail an einen Empfänger (config nfshare.mail).
 * Das Passwort steht nie in der E-Mail.
 */
class MailText
{
    /**
     * @return array{subject: string, body: string}
     */
    public static function for(Shipment $shipment, Recipient $recipient, string $url): array
    {
        $files = $shipment->files->map(fn ($file) => '- '.$file->name)->implode("\n");

        $expires = $shipment->expires_at
            ? $shipment->expires_at->timezone(config('app.timezone'))->format('d.m.Y H:i').' Uhr'
            : '–';

        $replace = [
            ':name' => $recipient->name ? ' '.$recipient->name : '',
            ':subject' => (string) $shipment->subject,
            ':files' => $files,
            ':url' => $url,
            ':expires' => $expires,
            ':password_hint' => $recipient->password_protected ? (string) config('nfshare.mail.password_hint', '') : '',
        ];

        return [
            'subject' => strtr((string) config('nfshare.mail.subject', ':subject'), $replace),
            'body' => strtr((string) config('nfshare.mail.body', ':url'), $replace),
        ];
    }
}
