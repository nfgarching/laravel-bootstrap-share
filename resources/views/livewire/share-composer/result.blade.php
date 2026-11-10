<div class="modal-body">
    <div class="d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-check-circle-fill text-success fs-3" aria-hidden="true"></i>
        <div>
            <div class="fw-semibold">{{ $result['subject'] }}</div>
            <div class="small text-body-secondary">Abrufbar bis {{ $result['expires'] }} Uhr</div>
        </div>
    </div>

    <p class="small">
        Jeder Empfänger hat einen eigenen Link. Das Passwort teilen Sie bitte auf einem anderen Weg mit
        (Telefon, SMS) – nie in derselben E-Mail wie den Link.
    </p>

    @foreach ($result['recipients'] as $i => $recipient)
        <div class="border rounded p-3 mb-2" wire:key="nfshare-res-{{ $i }}">
            <div class="fw-semibold text-break">
                {{ $recipient['name'] ?: $recipient['email'] }}
                @if ($recipient['name'])
                    <span class="fw-normal text-body-secondary small">&lt;{{ $recipient['email'] }}&gt;</span>
                @endif
            </div>

            @if ($recipient['url'])
                <div class="input-group input-group-sm mt-2">
                    <span class="input-group-text"><i class="bi bi-link-45deg" aria-hidden="true"></i></span>
                    <input type="text" class="form-control font-monospace" value="{{ $recipient['url'] }}" readonly
                        aria-label="Link für {{ $recipient['email'] }}" x-on:focus="$el.select()">
                    <button type="button" class="btn btn-outline-secondary"
                        x-on:click="navigator.clipboard.writeText(@js($recipient['url'])); copied = 'url{{ $i }}'; setTimeout(() => copied = null, 2000)">
                        <i class="bi" :class="copied === 'url{{ $i }}' ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
                        <span class="visually-hidden">Link kopieren</span>
                    </button>
                </div>

                @if ($recipient['password'])
                    <div class="input-group input-group-sm mt-2">
                        <span class="input-group-text"><i class="bi bi-key" aria-hidden="true"></i></span>
                        <input type="text" class="form-control font-monospace fw-semibold" value="{{ $recipient['password'] }}" readonly
                            aria-label="Passwort für {{ $recipient['email'] }}" x-on:focus="$el.select()">
                        <button type="button" class="btn btn-outline-secondary"
                            x-on:click="navigator.clipboard.writeText(@js($recipient['password'])); copied = 'pw{{ $i }}'; setTimeout(() => copied = null, 2000)">
                            <i class="bi" :class="copied === 'pw{{ $i }}' ? 'bi-check-lg' : 'bi-clipboard'" aria-hidden="true"></i>
                            <span class="visually-hidden">Passwort kopieren</span>
                        </button>
                    </div>
                @endif

                @if ($this->mailerAvailable && $recipient['mail'])
                    <button type="button" class="btn btn-sm btn-primary mt-2"
                        x-on:click="pendingMail = @js($recipient['mail']); bootstrap.Modal.getInstance($root).hide()">
                        <i class="bi bi-envelope" aria-hidden="true"></i> E-Mail mit Link schreiben
                    </button>
                @elseif ($recipient['mail'])
                    <details class="mt-2 small">
                        <summary>Text für die E-Mail</summary>
                        <pre class="mt-2 mb-0 p-2 bg-body-tertiary rounded text-wrap">{{ $recipient['mail']['subject'] }}

{{ $recipient['mail']['body'] }}</pre>
                    </details>
                @endif
            @else
                <div class="text-danger small mt-2">Für diesen Empfänger hat das Portal keinen Link geliefert.</div>
            @endif
        </div>
    @endforeach

    <p class="small text-body-secondary mb-0">
        @if ($result['stored'])
            Link und Passwort bleiben in der Übersicht der Sendungen (verschlüsselt) abrufbar.
        @else
            Link und Passwort werden nur jetzt angezeigt.
        @endif
    </p>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Schließen</button>
</div>
