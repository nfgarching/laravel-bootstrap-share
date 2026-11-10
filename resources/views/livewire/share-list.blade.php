<div>
    <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="nfshare-only-active" wire:model.live="onlyActive">
            <label class="form-check-label small" for="nfshare-only-active">nur abrufbare</label>
        </div>
        @if ($context === [])
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch" id="nfshare-mine" wire:model.live="mine">
                <label class="form-check-label small" for="nfshare-mine">nur meine</label>
            </div>
        @endif
        <span class="ms-auto small text-body-secondary" wire:loading>
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Portal wird abgefragt …
        </span>
    </div>

    @if ($notice)
        <div class="alert alert-success py-2 small">{{ $notice }}</div>
    @endif
    @if ($error)
        <div class="alert alert-danger py-2 small">{{ $error }}</div>
    @endif

    @if ($shipments->isEmpty())
        <p class="text-body-secondary small mb-0">Keine Sendungen über das Download-Portal.</p>
    @else
        <div class="list-group">
            @foreach ($shipments as $shipment)
                @php($open = $expanded === $shipment->id)
                <div class="list-group-item" wire:key="nfshare-s-{{ $shipment->id }}">
                    <button type="button" class="btn btn-link text-reset text-decoration-none p-0 w-100 text-start"
                        wire:click="toggle({{ $shipment->id }})" aria-expanded="{{ $open ? 'true' : 'false' }}">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="badge text-bg-{{ $shipment->statusColor() }}">{{ $shipment->statusLabel() }}</span>
                            <span class="fw-semibold text-break flex-grow-1">{{ $shipment->subject }}</span>
                            <span class="small text-body-secondary">
                                {{ $shipment->created_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}
                            </span>
                        </div>
                        <div class="small text-body-secondary mt-1">
                            @if ($shipment->reference)
                                {{ $shipment->reference }} ·
                            @endif
                            {{ $shipment->files->count() }} Datei(en) ·
                            {{ $shipment->recipients->map(fn ($r) => $r->name ?: $r->email)->implode(', ') }}
                            @if ($shipment->expires_at)
                                · bis {{ $shipment->expires_at->timezone(config('app.timezone'))->format('d.m.Y') }}
                            @endif
                            · <i class="bi bi-download" aria-hidden="true"></i> {{ $shipment->recipients->sum('downloads') }}
                        </div>
                    </button>

                    @if ($open)
                        <div class="mt-3">
                            @if ($shipment->error)
                                <div class="alert alert-danger py-2 small">{{ $shipment->error }}</div>
                            @endif

                            {{-- Dateien --}}
                            <div class="small mb-2">
                                @foreach ($shipment->files as $file)
                                    <div class="text-break"><i class="bi bi-file-earmark" aria-hidden="true"></i> {{ $file->name }}
                                        <span class="text-body-secondary">({{ \Nfgarching\Share\Support\FileGuard::humanSize($file->size) }})</span></div>
                                @endforeach
                            </div>

                            {{-- Empfänger --}}
                            <table class="table table-sm small align-middle mb-2">
                                <thead>
                                    <tr>
                                        <th>Empfänger</th>
                                        <th>Geöffnet</th>
                                        <th>Abrufe</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($shipment->recipients as $recipient)
                                        <tr wire:key="nfshare-sr-{{ $recipient->id }}">
                                            <td class="text-break">
                                                {{ $recipient->label() }}
                                                @if ($recipient->password_protected)
                                                    <i class="bi bi-key text-body-secondary" title="mit Passwort" aria-label="mit Passwort"></i>
                                                @endif
                                                @if ($recipient->locked)
                                                    <span class="badge text-bg-danger">gesperrt</span>
                                                @endif
                                            </td>
                                            <td>{{ $recipient->opened_at?->timezone(config('app.timezone'))->format('d.m. H:i') ?? '–' }}</td>
                                            <td>
                                                {{ $recipient->downloads }}
                                                @if ($recipient->first_downloaded_at)
                                                    <span class="text-body-secondary">(erstmals {{ $recipient->first_downloaded_at->timezone(config('app.timezone'))->format('d.m. H:i') }})</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-nowrap">
                                                @if ($recipient->locked && $shipment->isActive())
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="unlock({{ $recipient->id }})">Entsperren</button>
                                                @endif
                                                @if ($recipient->url && $shipment->isActive() && ! isset($revealed[$recipient->id]))
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="reveal({{ $recipient->id }})">Zugang zeigen</button>
                                                @endif
                                            </td>
                                        </tr>
                                        @if (isset($revealed[$recipient->id]) && $recipient->url)
                                            <tr>
                                                <td colspan="4">
                                                    <div class="font-monospace text-break user-select-all">{{ $recipient->url }}</div>
                                                    @if ($recipient->password)
                                                        <div class="font-monospace fw-semibold user-select-all">{{ $recipient->password }}</div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>

                            {{-- Aktionen --}}
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                @if ($shipment->portal_id)
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="refreshShipment({{ $shipment->id }})">
                                        <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Status laden
                                    </button>
                                @endif
                                @if ($shipment->isActive())
                                    <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="extend({{ $shipment->id }}, 7)">
                                        <i class="bi bi-calendar-plus" aria-hidden="true"></i> +7 Tage
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" wire:click="revoke({{ $shipment->id }})"
                                        wire:confirm="Sendung widerrufen? Alle Links werden sofort ungültig und die Dateien im Portal gelöscht.">
                                        <i class="bi bi-x-octagon" aria-hidden="true"></i> Widerrufen
                                    </button>
                                @endif
                            </div>

                            {{-- Protokoll --}}
                            @if ($events && $events->isNotEmpty())
                                <details class="small">
                                    <summary>Protokoll ({{ $events->count() }})</summary>
                                    <ul class="list-unstyled mt-2 mb-0">
                                        @foreach ($events as $event)
                                            <li>
                                                <span class="text-body-secondary">{{ $event->occurred_at->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                                                {{ $event->label() }}
                                                @if ($event->recipient_portal_id && ($r = $recipientsByPortalId->get($event->recipient_portal_id)))
                                                    – {{ $r->name ?: $r->email }}
                                                @endif
                                                @if ($event->file_portal_id && ($f = $shipment->files->firstWhere('portal_id', $event->file_portal_id)))
                                                    – {{ $f->name }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-2">{{ $shipments->links() }}</div>
    @endif
</div>
