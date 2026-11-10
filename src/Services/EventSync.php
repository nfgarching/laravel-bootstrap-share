<?php

namespace Nfgarching\Share\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Events\PortalEventReceived;
use Nfgarching\Share\Models\PortalEvent;
use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;
use Throwable;

/**
 * Holt das Protokoll aus dem Portal (GET /events) und überträgt es auf die
 * lokalen Sendungen. Der Cursor ist die höchste bereits gespeicherte
 * portal_event_id; ein abgebrochener Lauf setzt beim nächsten Mal dort fort.
 */
class EventSync
{
    private const PAGE_SIZE = 200;

    private const MAX_PAGES = 50;

    public function __construct(private readonly PortalClient $client) {}

    /**
     * @return int Anzahl neuer Einträge
     */
    public function run(): int
    {
        $cursor = PortalEvent::cursor();
        $count = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $this->client->events($cursor, self::PAGE_SIZE);

            foreach ($result['data'] ?? [] as $remote) {
                if ($this->store($remote)) {
                    $count++;
                }

                $cursor = max($cursor, (int) $remote['id']);
            }

            if (! ($result['has_more'] ?? false)) {
                break;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function store(array $remote): bool
    {
        if (PortalEvent::where('portal_event_id', $remote['id'])->exists()) {
            return false;
        }

        $shipment = isset($remote['shipment_id'])
            ? Shipment::where('portal_id', $remote['shipment_id'])->first()
            : null;

        $recipient = $shipment && isset($remote['recipient_id'])
            ? $shipment->recipients()->where('portal_id', $remote['recipient_id'])->first()
            : null;

        $event = DB::transaction(function () use ($remote, $shipment, $recipient) {
            $event = PortalEvent::create([
                'portal_event_id' => $remote['id'],
                'share_shipment_id' => $shipment?->id,
                'type' => mb_substr((string) $remote['type'], 0, 50),
                'shipment_portal_id' => $remote['shipment_id'] ?? null,
                'recipient_portal_id' => $remote['recipient_id'] ?? null,
                'file_portal_id' => $remote['file_id'] ?? null,
                'ip' => $remote['ip'] ?? null,
                'meta' => $remote['meta'] ?? null,
                'occurred_at' => Carbon::parse($remote['created_at']),
            ]);

            if ($shipment !== null) {
                $this->apply($event, $shipment, $recipient);
            }

            return $event;
        });

        if ($event->type === 'file.downloaded' && $recipient !== null) {
            $this->notifyFirstDownload($shipment, $recipient->refresh());
        }

        event(new PortalEventReceived($event, $shipment, $recipient));

        return true;
    }

    private function apply(PortalEvent $event, Shipment $shipment, ?Recipient $recipient): void
    {
        $at = $event->occurred_at;

        switch ($event->type) {
            case 'link.opened':
                if ($recipient && $recipient->opened_at === null) {
                    $recipient->forceFill(['opened_at' => $at])->save();
                }
                break;

            case 'file.downloaded':
                if ($recipient) {
                    $recipient->forceFill([
                        'first_downloaded_at' => $recipient->first_downloaded_at ?? $at,
                        // Absolut aus dem Protokoll zählen: Wiederholte Läufe zählen nicht doppelt.
                        'downloads' => max($recipient->downloads, PortalEvent::query()
                            ->where('share_shipment_id', $shipment->id)
                            ->where('recipient_portal_id', $recipient->portal_id)
                            ->where('type', 'file.downloaded')
                            ->count()),
                    ])->save();
                }
                break;

            case 'link.locked':
                $recipient?->forceFill(['locked' => true])->save();
                break;

            case 'shipment.updated':
                $unlocked = (array) ($event->meta['unlocked'] ?? []);

                if ($unlocked) {
                    $shipment->recipients()->whereIn('portal_id', $unlocked)->update(['locked' => false]);
                }

                if (isset($event->meta['expires_at'])) {
                    $shipment->forceFill(['expires_at' => Carbon::parse($event->meta['expires_at'])])->save();
                }

                if (isset($event->meta['max_downloads'])) {
                    $shipment->forceFill(['max_downloads' => (int) $event->meta['max_downloads']])->save();
                }
                break;

            case 'shipment.revoked':
                $shipment->forceFill(['status' => Shipment::STATUS_REVOKED])->save();
                break;

            case 'shipment.expired':
                $shipment->forceFill(['status' => Shipment::STATUS_EXPIRED])->save();
                break;
        }

        $shipment->forceFill(['synced_at' => now()])->save();
    }

    /**
     * Benachrichtigt den Absender, wenn ein Empfänger zum ersten Mal eine Datei abruft.
     */
    private function notifyFirstDownload(Shipment $shipment, Recipient $recipient): void
    {
        if (! config('nfshare.sync.notify_on_download', true) || $recipient->notified_at !== null) {
            return;
        }

        try {
            $userModel = (string) config('auth.providers.users.model');
            $user = $shipment->user_id && class_exists($userModel) ? $userModel::find($shipment->user_id) : null;

            // Auch ohne Mail als erledigt markieren, damit spätere Abrufe nicht doch noch melden.
            $recipient->forceFill(['notified_at' => now()])->save();

            if (! $user?->email) {
                return;
            }

            $tz = config('app.timezone');
            $files = $shipment->files->pluck('name')->implode(', ');

            $text = "{$recipient->label()} hat Dokumente aus dem Portal abgerufen.\n\n"
                ."Betreff: {$shipment->subject}\n"
                .($shipment->reference ? "Referenz: {$shipment->reference}\n" : '')
                ."Dateien: {$files}\n"
                .'Freigegeben: '.($shipment->released_at?->timezone($tz)->format('d.m.Y H:i') ?? '–')."\n"
                .'Erster Abruf: '.($recipient->first_downloaded_at?->timezone($tz)->format('d.m.Y H:i') ?? '–')."\n";

            $subject = 'Abgerufen: '.($shipment->reference ? $shipment->reference.' – ' : '').$shipment->subject;

            Mail::mailer(config('nfshare.sync.notify_mailer'))
                ->raw($text, fn ($message) => $message->to($user->email)->subject($subject));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
