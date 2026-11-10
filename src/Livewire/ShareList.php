<?php

namespace Nfgarching\Share\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;
use Nfgarching\Share\Services\ShareService;
use Throwable;

/**
 * Übersicht der Sendungen, wahlweise für einen Kontext (z. B. eine Akte):
 *
 *     <livewire:nf-share-list :context="['akte' => $akte->id]" />
 *     <livewire:nf-share-list />    alle Sendungen (Seite nfshare.index)
 *
 * Aktionen: Status aus dem Portal laden, Frist verlängern, widerrufen,
 * gesperrten Empfänger freigeben, gespeicherten Link/Passwort anzeigen.
 */
class ShareList extends Component
{
    use WithPagination;

    #[Locked]
    public array $context = [];

    /** Nur eigene Sendungen (Seite ohne Kontext) */
    public bool $mine = false;

    public bool $onlyActive = false;

    #[Locked]
    public ?int $expanded = null;

    /** Aufgedeckte Zugangsdaten: [Empfänger-ID => true] */
    #[Locked]
    public array $revealed = [];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(array $context = []): void
    {
        $this->context = $context;
        $this->authorizeList();
    }

    #[On('nfshare-sent')]
    public function refreshList(): void
    {
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->authorizeShipment(Shipment::findOrFail($id));

        $this->expanded = $this->expanded === $id ? null : $id;
        $this->revealed = [];
    }

    public function reveal(int $recipientId): void
    {
        $recipient = Recipient::findOrFail($recipientId);
        $this->authorizeShipment($recipient->shipment);

        $this->revealed[$recipientId] = true;
    }

    public function refreshShipment(int $id, ShareService $service): void
    {
        $this->run($id, fn (Shipment $s) => $service->refresh($s), 'Status aus dem Portal geladen.');
    }

    public function extend(int $id, int $days, ShareService $service): void
    {
        $days = max(1, min($days, 90));

        $this->run($id, function (Shipment $s) use ($service, $days) {
            $base = $s->expires_at && $s->expires_at->isFuture() ? $s->expires_at->copy() : now();
            return $service->extend($s, Carbon::parse($base)->addDays($days));
        }, "Frist um {$days} Tage verlängert.");
    }

    public function revoke(int $id, ShareService $service): void
    {
        $this->run($id, fn (Shipment $s) => $service->revoke($s), 'Sendung widerrufen. Die Links sind ungültig, die Dateien im Portal gelöscht.');
    }

    public function unlock(int $recipientId, ShareService $service): void
    {
        $recipient = Recipient::findOrFail($recipientId);

        $this->run($recipient->share_shipment_id, fn () => $service->unlock($recipient), 'Empfänger wieder freigegeben.');
    }

    /**
     * @param  callable(Shipment): mixed  $action
     */
    private function run(int $id, callable $action, string $success): void
    {
        $this->notice = $this->error = null;

        $shipment = Shipment::findOrFail($id);
        $this->authorizeShipment($shipment);

        try {
            $action($shipment);
            $this->notice = $success;
        } catch (PortalException $e) {
            report($e);
            $this->error = $e->userMessage();
        } catch (Throwable $e) {
            report($e);
            $this->error = $e->getMessage();
        }
    }

    private function authorizeList(): void
    {
        abort_unless(auth()->check(), 403);

        if ($gate = config('nfshare.gate')) {
            Gate::authorize($gate, [$this->context]);
        }
    }

    /** Eine Aktion darf nur Sendungen betreffen, die diese Liste auch zeigt. */
    private function authorizeShipment(Shipment $shipment): void
    {
        $this->authorizeList();

        if ($this->context !== []) {
            abort_unless($shipment->context_key === Shipment::contextKey($this->context), 404);
        }
    }

    public function render()
    {
        $query = Shipment::query()
            ->with(['recipients', 'files'])
            ->latest('id');

        if ($this->context !== []) {
            $query->forContext($this->context);
        }

        if ($this->mine) {
            $query->where('user_id', auth()->id());
        }

        if ($this->onlyActive) {
            $query->where('status', Shipment::STATUS_RELEASED)->where('expires_at', '>', now());
        }

        $shipments = $query->paginate(15);

        $events = $this->expanded
            ? Shipment::find($this->expanded)?->events()->orderByDesc('portal_event_id')->limit(50)->get()
            : null;

        return view('nfshare::livewire.share-list', [
            'shipments' => $shipments,
            'events' => $events,
            'recipientsByPortalId' => $this->expanded
                ? Recipient::where('share_shipment_id', $this->expanded)->get()->keyBy('portal_id')
                : collect(),
        ]);
    }
}
