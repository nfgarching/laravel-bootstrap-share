<?php

namespace Nfgarching\Share\Support;

use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;
use Nfgarching\Share\Models\UploadRequest;

/**
 * Betreff und Text der E-Mail an einen Empfänger (config nfshare.mail).
 * Das Passwort steht nie in der E-Mail.
 */
class MailText
{
    /**
     * @return array{subject: string, body: string}
     */
    public static function for(Shipment $shipment, Recipient $recipient, string $url, ?array $upload = null): array
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

        $body = strtr((string) config('nfshare.mail.body', ':url'), $replace);

        if ($upload !== null) {
            $body .= "\n".strtr((string) config('nfshare.mail.upload', ''), self::uploadReplacements($upload['request'], $upload['url']));
        }

        return [
            'subject' => strtr((string) config('nfshare.mail.subject', ':subject'), $replace),
            'body' => $body,
        ];
    }

    /**
     * Nur Anforderung, ohne bereitgestellte Dateien.
     *
     * @return array{subject: string, body: string}
     */
    public static function forRequest(UploadRequest $request, string $url): array
    {
        $replace = [
            ':name' => $request->name ? ' '.$request->name : '',
            ':subject' => (string) $request->subject,
            ...self::uploadReplacements($request, $url),
        ];

        return [
            'subject' => strtr((string) config('nfshare.mail.request_subject', ':subject'), $replace),
            'body' => strtr((string) config('nfshare.mail.request_body', ':upload_url'), $replace),
        ];
    }

    /** @return array<string, string> */
    private static function uploadReplacements(UploadRequest $request, string $url): array
    {
        return [
            ':upload_url' => $url,
            ':upload_subject' => (string) $request->subject,
            ':upload_expires' => $request->expires_at
                ? $request->expires_at->timezone(config('app.timezone'))->format('d.m.Y')
                : '–',
        ];
    }
}
