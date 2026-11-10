<?php

namespace Nfgarching\Share\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Events\ShipmentReleased;
use Nfgarching\Share\Models\File;
use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;
use RuntimeException;
use Throwable;

/**
 * Stellt Dateien über das Portal bereit und hält die lokale Spiegelung aktuell.
 *
 *     $result = app(ShareService::class)->share([
 *         'context'       => ['akte' => 123],
 *         'reference'     => '26/0001',
 *         'subject'       => 'Entwurf Vereinbarung',
 *         'message'       => 'Bitte prüfen Sie den Entwurf.',
 *         'expires_at'    => now()->addDays(14),
 *         'max_downloads' => 5,
 *         'recipients'    => [['email' => 'm@example.com', 'name' => 'Erika Muster', 'password' => true]],
 *         'files'         => [['disk' => 'local', 'path' => 'akten/123/vertrag.pdf', 'name' => 'Vertrag.pdf']],
 *     ], userId: auth()->id());
 *
 *     $result->links[$recipientId]['url'], ['password']
 *
 * Die Dateien werden hier nicht mehr geprüft – Aufrufer aus dem Browser
 * (Livewire) müssen sie vorher durch FileGuard schicken.
 */
class ShareService
{
    public function __construct(private readonly PortalClient $client) {}

    /**
     * @param  array{context?: array, reference?: ?string, subject: string, message?: ?string, expires_at?: mixed, max_downloads?: ?int, recipients: list<array{email: string, name?: ?string, password?: bool}>, files: list<array{disk: string, path: string, name: string}>}  $data
     *
     * @throws PortalException|RuntimeException
     */
    public function share(array $data, ?int $userId = null): ShareResult
    {
        if (empty($data['files'])) {
            throw new RuntimeException('Keine Datei ausgewählt.');
        }

        if (empty($data['recipients'])) {
            throw new RuntimeException('Kein Empfänger angegeben.');
        }

        $context = (array) ($data['context'] ?? []);
        $expiresAt = self::capExpiry(isset($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : now()->addDays((int) config('nfshare.defaults.expires_days', 14)));

        $shipment = DB::transaction(function () use ($data, $context, $expiresAt, $userId) {
            $shipment = Shipment::create([
                'user_id' => $userId,
                'context' => $context ?: null,
                'context_key' => Shipment::contextKey($context),
                'reference' => $data['reference'] ?? null,
                'subject' => $data['subject'],
                'message' => $data['message'] ?? null,
                'status' => Shipment::STATUS_PENDING,
                'max_downloads' => (int) ($data['max_downloads'] ?? config('nfshare.defaults.max_downloads', 5)),
                'expires_at' => $expiresAt,
            ]);

            foreach ($data['recipients'] as $recipient) {
                $shipment->recipients()->create([
                    'email' => strtolower(trim($recipient['email'])),
                    'name' => $recipient['name'] ?? null,
                    'password_protected' => (bool) ($recipient['password'] ?? false),
                ]);
            }

            foreach ($data['files'] as $file) {
                $shipment->files()->create([
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'name' => $file['name'],
                    'size' => (int) ($file['size'] ?? 0),
                ]);
            }

            return $shipment;
        });

        try {
            $links = $this->transfer($shipment);
        } catch (Throwable $e) {
            $this->fail($shipment, $e);

            throw $e;
        }

        $shipment->refresh()->load(['recipients', 'files']);

        event(new ShipmentReleased($shipment));

        return new ShareResult($shipment, $links);
    }

    /**
     * Sendung im Portal anlegen, Dateien übertragen, freigeben.
     *
     * @return array<int, array{url: string, password: ?string}>
     */
    private function transfer(Shipment $shipment): array
    {
        $shipment->loadMissing(['recipients', 'files']);

        $remote = $this->client->createShipment([
            'reference' => $shipment->reference,
            'subject' => $shipment->subject,
            'message' => $shipment->message,
            'expires_at' => $shipment->expires_at?->toIso8601String(),
            'max_downloads' => $shipment->max_downloads,
            'recipients' => $shipment->recipients->map(fn (Recipient $r) => [
                'email' => $r->email,
                'name' => $r->name,
                'password' => $r->password_protected,
            ])->values()->all(),
        ], 'nfshare-'.$shipment->id.'-'.$shipment->created_at->timestamp);

        $shipment->forceFill(['portal_id' => $remote['id']])->save();
        $this->mapRecipients($shipment, $remote);

        foreach ($shipment->files as $file) {
            $this->upload($shipment, $file);
        }

        $released = $this->client->release($shipment->portal_id);

        $links = [];
        $store = (bool) config('nfshare.store_secrets', true);
        $byPortalId = $shipment->recipients->keyBy('portal_id');

        foreach ($released['recipients'] ?? [] as $remoteRecipient) {
            $recipient = $byPortalId->get($remoteRecipient['id'] ?? null);

            if ($recipient === null || empty($remoteRecipient['url'])) {
                continue;
            }

            $links[$recipient->id] = [
                'url' => $remoteRecipient['url'],
                'password' => $remoteRecipient['password'] ?? null,
            ];

            if ($store) {
                $recipient->forceFill([
                    'url' => $remoteRecipient['url'],
                    'password' => $remoteRecipient['password'] ?? null,
                ])->save();
            }
        }

        $shipment->forceFill([
            'status' => Shipment::STATUS_RELEASED,
            'released_at' => isset($released['released_at']) ? Carbon::parse($released['released_at']) : now(),
            'expires_at' => isset($released['expires_at']) ? Carbon::parse($released['expires_at']) : $shipment->expires_at,
            'synced_at' => now(),
            'error' => null,
        ])->save();

        return $links;
    }

    private function upload(Shipment $shipment, File $file): void
    {
        $storage = Storage::disk($file->disk);

        // Prüfsumme in einem ersten Durchgang; der Datenstrom entfernter Disks
        // (SFTP, S3) lässt sich nicht immer zurückspulen, daher zweimal öffnen.
        $hashStream = $storage->readStream($file->path);

        if (! is_resource($hashStream)) {
            throw new RuntimeException("Datei „{$file->name}“ lässt sich nicht lesen.");
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $hashStream);
        fclose($hashStream);
        $sha256 = hash_final($context);

        $size = (int) $storage->size($file->path);

        try {
            $mimeType = $storage->mimeType($file->path) ?: null;
        } catch (Throwable) {
            $mimeType = null;
        }

        $stream = $storage->readStream($file->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Datei „{$file->name}“ lässt sich nicht lesen.");
        }

        try {
            $remote = $this->client->uploadFile($shipment->portal_id, $stream, $file->name, $sha256, $size, $mimeType);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $file->forceFill([
            'portal_id' => $remote['id'],
            'sha256' => $remote['sha256'] ?? $sha256,
            'size' => $remote['size'] ?? $size,
        ])->save();
    }

    private function fail(Shipment $shipment, Throwable $e): void
    {
        $message = $e instanceof PortalException ? $e->userMessage() : $e->getMessage();

        $shipment->forceFill(['status' => Shipment::STATUS_FAILED, 'error' => mb_substr($message, 0, 1000)])->save();

        // Angelegten, aber nicht freigegebenen Entwurf im Portal sofort löschen
        // (sonst entfernt ihn erst der Löschlauf des Portals nach 24 Stunden).
        if ($shipment->portal_id !== null) {
            try {
                $this->client->revokeShipment($shipment->portal_id);
            } catch (Throwable $cleanup) {
                report($cleanup);
            }
        }
    }

    // ------------------------------------------------------------------ Verwaltung

    public function revoke(Shipment $shipment): Shipment
    {
        if ($shipment->portal_id === null) {
            $shipment->forceFill(['status' => Shipment::STATUS_REVOKED])->save();

            return $shipment;
        }

        $this->apply($shipment, $this->client->revokeShipment($shipment->portal_id));

        return $shipment->refresh();
    }

    public function extend(Shipment $shipment, Carbon $expiresAt): Shipment
    {
        $this->requireActive($shipment);

        $this->apply($shipment, $this->client->updateShipment($shipment->portal_id, [
            'expires_at' => self::capExpiry($expiresAt)->toIso8601String(),
        ]));

        return $shipment->refresh();
    }

    public function setMaxDownloads(Shipment $shipment, int $maxDownloads): Shipment
    {
        $this->requireActive($shipment);

        $this->apply($shipment, $this->client->updateShipment($shipment->portal_id, [
            'max_downloads' => $maxDownloads,
        ]));

        return $shipment->refresh();
    }

    public function unlock(Recipient $recipient): Recipient
    {
        $shipment = $recipient->shipment;
        $this->requireActive($shipment);

        $this->apply($shipment, $this->client->updateShipment($shipment->portal_id, [
            'unlock_recipients' => [$recipient->portal_id],
        ]));

        return $recipient->refresh();
    }

    /** Status aus dem Portal neu laden. */
    public function refresh(Shipment $shipment): Shipment
    {
        if ($shipment->portal_id !== null) {
            $this->apply($shipment, $this->client->getShipment($shipment->portal_id));
        }

        return $shipment->refresh();
    }

    /**
     * Übernimmt den Stand eines Sendungsobjekts aus dem Portal.
     *
     * @param  array<string, mixed>  $remote
     */
    public function apply(Shipment $shipment, array $remote): void
    {
        $status = match ($remote['status'] ?? null) {
            'released' => Shipment::STATUS_RELEASED,
            'revoked' => Shipment::STATUS_REVOKED,
            'expired' => Shipment::STATUS_EXPIRED,
            default => $shipment->status,
        };

        $shipment->forceFill([
            'status' => $status,
            'max_downloads' => (int) ($remote['max_downloads'] ?? $shipment->max_downloads),
            'expires_at' => isset($remote['expires_at']) ? Carbon::parse($remote['expires_at']) : $shipment->expires_at,
            'synced_at' => now(),
        ])->save();

        $recipients = $shipment->recipients()->get()->keyBy('portal_id');

        foreach ($remote['recipients'] ?? [] as $remoteRecipient) {
            $recipient = $recipients->get($remoteRecipient['id'] ?? null);

            if ($recipient === null) {
                continue;
            }

            $recipient->forceFill([
                'opened_at' => isset($remoteRecipient['opened_at']) ? Carbon::parse($remoteRecipient['opened_at']) : $recipient->opened_at,
                'locked' => (bool) ($remoteRecipient['locked'] ?? false),
                'downloads' => max($recipient->downloads, (int) ($remoteRecipient['downloads'] ?? 0)),
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function mapRecipients(Shipment $shipment, array $remote): void
    {
        $byEmail = $shipment->recipients->keyBy(fn (Recipient $r) => strtolower((string) $r->email));

        foreach ($remote['recipients'] ?? [] as $remoteRecipient) {
            $recipient = $byEmail->get(strtolower((string) ($remoteRecipient['email'] ?? '')));

            $recipient?->forceFill(['portal_id' => $remoteRecipient['id']])->save();
        }
    }

    /**
     * Das Portal lässt höchstens 90 Tage ab jetzt zu (PORTAL_MAX_EXPIRES_DAYS).
     * Mit Abstand kappen, damit Laufzeit und Uhrenabweichung nicht zur Ablehnung führen.
     */
    public static function capExpiry(Carbon $expiresAt): Carbon
    {
        $limit = now()->addDays(90)->subMinutes(10);

        return $expiresAt->gt($limit) ? $limit : $expiresAt;
    }

    private function requireActive(Shipment $shipment): void
    {
        if ($shipment->portal_id === null || ! in_array($shipment->status, [Shipment::STATUS_RELEASED], true)) {
            throw new RuntimeException('Die Sendung ist nicht mehr aktiv.');
        }
    }
}
