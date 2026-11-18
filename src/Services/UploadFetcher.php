<?php

namespace Nfgarching\Share\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Events\UploadReceived;
use Nfgarching\Share\Models\Upload;
use Nfgarching\Share\Models\UploadRequest;
use Nfgarching\Share\Support\KeyStore;
use Nfgarching\Share\Support\StreamDecryptor;
use RuntimeException;
use Throwable;

/**
 * Holt eingegangene Dateien aus dem Portal (php artisan share:fetch-uploads).
 *
 * Je Datei: verschlüsselt herunterladen → Schlüssel mit dem privaten Schlüssel
 * öffnen → entschlüsseln → SHA-256 prüfen → optional Virenprüfung → ablegen
 * (Disk oder Posteingang von nfgarching/files) → im Portal quittieren.
 * Erst die Quittung löscht die Datei im Portal; scheitert ein Schritt davor,
 * wird sie beim nächsten Lauf erneut versucht.
 */
class UploadFetcher
{
    /** @var array<string, list<Upload>> neue Uploads je Vorgang, für die Benachrichtigung */
    private array $batches = [];

    public function __construct(
        private readonly PortalClient $client,
        private readonly KeyStore $keys,
    ) {}

    /**
     * @return array{fetched: int, quarantined: int, failed: int}
     */
    public function run(): array
    {
        $stats = ['fetched' => 0, 'quarantined' => 0, 'failed' => 0];
        $this->batches = [];

        foreach ($this->client->uploads(100) as $remote) {
            try {
                $upload = $this->fetch($remote);
                $stats[$upload->status === Upload::STATUS_QUARANTINED ? 'quarantined' : 'fetched']++;
            } catch (Throwable $e) {
                report($e);
                $stats['failed']++;
            }
        }

        foreach ($this->batches as $uploads) {
            $this->notify($uploads);
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function fetch(array $remote): Upload
    {
        // Schon abgelegt, aber die Quittung ging verloren: nur quittieren
        $existing = Upload::where('portal_id', $remote['id'])->first();

        if ($existing !== null) {
            $this->client->ackUpload($remote['id']);

            return $existing;
        }

        $temporary = $this->temporaryDirectory();
        $encrypted = $temporary.'/'.Str::random(16).'.enc';
        $plain = $temporary.'/'.Str::random(16).'.bin';

        try {
            $this->client->downloadUpload($remote['id'], $encrypted);

            $result = StreamDecryptor::decryptFile($encrypted, $plain, $this->keys->openSealed((string) $remote['sealed_key']));

            if (! hash_equals(strtolower((string) $remote['sha256']), $result['sha256'])) {
                throw new RuntimeException("Prüfsumme von „{$remote['name']}“ stimmt nicht. Die Datei bleibt im Portal.");
            }

            $request = isset($remote['request_id'])
                ? UploadRequest::where('portal_id', $remote['request_id'])->first()
                : null;

            $upload = new Upload([
                'portal_id' => $remote['id'],
                'share_upload_request_id' => $request?->id,
                'context' => $request?->context,
                'context_key' => $request?->context_key,
                'batch' => $remote['batch'],
                'via' => $remote['via'] ?? 'link',
                'sender_email' => $remote['sender_email'],
                'sender_name' => $remote['sender_name'] ?? null,
                'subject' => $remote['subject'] ?? null,
                'message' => $remote['message'] ?? null,
                'name' => $this->safeName((string) $remote['name']),
                'mime_type' => $remote['mime_type'] ?? null,
                'size' => $result['size'],
                'sha256' => $result['sha256'],
                'received_at' => isset($remote['created_at']) ? Carbon::parse($remote['created_at']) : now(),
            ]);

            if ($this->infected($plain)) {
                $this->storeOnDisk($upload, $plain, (string) config('nfshare.uploads.quarantine_directory', 'nfshare/quarantaene'));
                $upload->status = Upload::STATUS_QUARANTINED;
            } elseif ($this->filesAvailable()) {
                $this->storeInFiles($upload, $plain);
            } else {
                $this->storeOnDisk($upload, $plain, (string) config('nfshare.uploads.directory', 'nfshare/eingang/{Y}/{m}'));
                $upload->status = Upload::STATUS_STORED;
            }

            $upload->save();
        } finally {
            @unlink($encrypted);
            @unlink($plain);
        }

        $this->client->ackUpload($remote['id']);

        if ($upload->status !== Upload::STATUS_QUARANTINED) {
            event(new UploadReceived($upload));
        }

        $this->batches[$upload->batch][] = $upload;

        return $upload;
    }

    /**
     * Virenprüfung über einen Befehl (nfshare.uploads.scan_command).
     * Exit 0 = sauber, 1 = Fund; alles andere ist ein Fehler und bricht ab.
     */
    private function infected(string $path): bool
    {
        $command = config('nfshare.uploads.scan_command');

        if (blank($command)) {
            return false;
        }

        $result = Process::timeout(300)->run(str_replace('{file}', escapeshellarg($path), (string) $command));

        return match ($result->exitCode()) {
            0 => false,
            1 => true,
            default => throw new RuntimeException('Virenprüfung fehlgeschlagen: '.trim($result->errorOutput() ?: $result->output())),
        };
    }

    private function storeOnDisk(Upload $upload, string $plain, string $directory): void
    {
        $disk = (string) config('nfshare.uploads.disk', 'local');
        $directory = trim(strtr($directory, ['{Y}' => date('Y'), '{m}' => date('m'), '{d}' => date('d')]), '/');
        $extension = strtolower(pathinfo($upload->name, PATHINFO_EXTENSION));
        $path = $directory.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');

        $stream = fopen($plain, 'rb');

        try {
            if (! Storage::disk($disk)->writeStream($path, $stream)) {
                throw new RuntimeException("„{$upload->name}“ lässt sich nicht ablegen ({$disk}:{$path}).");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $upload->disk = $disk;
        $upload->path = $path;
    }

    /**
     * Posteingang von nfgarching/files: Datei anlegen (als fremdes Dokument,
     * „Original“), dann NewFiles auslösen – wie beim Upload in der Dateiverwaltung.
     * Besitzer: model_type/model_id aus dem Kontext, sonst die Vorgabe des Files-Packages.
     */
    private function storeInFiles(Upload $upload, string $plain): void
    {
        $fileClass = 'Nfgarching\\Files\\Models\\File';
        $eventClass = 'Nfgarching\\Files\\Events\\NewFiles';

        $file = $fileClass::storeUpload(
            new UploadedFile($plain, $upload->name, $upload->mime_type, null, true),
            ['original' => true],
        );

        $context = (array) ($upload->context ?? []);
        $modelType = $context['model_type'] ?? null;
        $modelId = $context['model_id'] ?? null;

        if ($modelType && $modelId) {
            $file->update(['model_type' => $modelType, 'model_id' => (int) $modelId]);
        }

        $upload->status = Upload::STATUS_FILED;
        $upload->file_id = $file->id;
        $upload->disk = $file->disk;
        $upload->path = $file->file;

        if (class_exists($eventClass)) {
            event(new $eventClass(
                [$file->id],
                $modelType,
                $modelId,
                $upload->subject ?: 'Upload aus dem Dokumentenportal',
                [
                    'source' => 'portal',
                    'context' => $context,
                    'sender' => $upload->senderLabel(),
                    'message' => $upload->message,
                    'sha256' => $upload->sha256,
                    'portal_upload_id' => $upload->portal_id,
                ],
            ));
        }
    }

    private function filesAvailable(): bool
    {
        return (bool) config('nfshare.uploads.files', true) && class_exists('Nfgarching\\Files\\Models\\File');
    }

    /**
     * Benachrichtigt den Benutzer, der die Unterlagen angefordert hat (bzw.
     * nfshare.uploads.notify_address bei freien Uploads), einmal je Vorgang.
     *
     * @param  list<Upload>  $uploads
     */
    private function notify(array $uploads): void
    {
        $first = $uploads[0];
        $request = $first->uploadRequest;
        $to = null;

        try {
            if ($request?->user_id) {
                $userModel = (string) config('auth.providers.users.model');
                $to = class_exists($userModel) ? $userModel::find($request->user_id)?->email : null;
            }

            $to ??= config('nfshare.uploads.notify_address');

            if (! $to) {
                return;
            }

            $tz = config('app.timezone');
            $lines = collect($uploads)->map(fn (Upload $u) => '- '.$u->name
                .($u->status === Upload::STATUS_QUARANTINED ? '  ⚠ VIRENFUND – in Quarantäne' : ''))->implode("\n");

            $text = "{$first->senderLabel()} hat Unterlagen über das Dokumentenportal hochgeladen.\n\n"
                .'Betreff: '.($first->subject ?: '–')."\n"
                .($request?->reference ? "Referenz: {$request->reference}\n" : '')
                .'Eingegangen: '.$first->received_at?->timezone($tz)->format('d.m.Y H:i')."\n"
                ."Dateien:\n{$lines}\n"
                .($first->message ? "\nNachricht des Absenders:\n{$first->message}\n" : '');

            $subject = 'Eingang: '.($request?->reference ? $request->reference.' – ' : '').($first->subject ?: 'Upload');

            Mail::mailer(config('nfshare.sync.notify_mailer'))
                ->raw($text, fn ($message) => $message->to($to)->subject($subject));

            Upload::whereIn('id', collect($uploads)->pluck('id'))->update(['notified_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = storage_path('app/nfshare/tmp');

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name), " .\t");

        return mb_substr($name !== '' ? $name : 'Datei', 0, 200);
    }
}
