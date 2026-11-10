<form wire:submit="share" style="display: contents">
    <div class="modal-body">
        @if ($error)
            <div class="alert alert-danger d-flex gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <div>{{ $error }}</div>
            </div>
        @endif

        {{-- ------------------------------------------------------------ Dateien --}}
        <h6 class="text-body-secondary text-uppercase small mb-2">Dateien</h6>

        @forelse ($files as $i => $file)
            <div class="form-check" wire:key="nfshare-file-{{ $i }}">
                <input class="form-check-input" type="checkbox" id="nfshare-file-{{ $i }}"
                    @checked($file['active']) wire:click="toggleFile({{ $i }})">
                <label class="form-check-label text-break" for="nfshare-file-{{ $i }}">
                    {{ $file['name'] }}
                    <span class="text-body-secondary small">({{ \Nfgarching\Share\Support\FileGuard::humanSize($file['size']) }})</span>
                </label>
            </div>
        @empty
            <p class="text-body-secondary small mb-0">Keine Datei übergeben. Das Modal wird aus der Akte oder der Dateiverwaltung mit Dateien geöffnet.</p>
        @endforelse
        @error('files')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror

        {{-- ------------------------------------------------------------ Empfänger --}}
        <h6 class="text-body-secondary text-uppercase small mt-4 mb-2">Empfänger</h6>

        @if ($recipients)
            <ul class="list-group mb-2">
                @foreach ($recipients as $i => $recipient)
                    <li class="list-group-item d-flex align-items-center gap-2" wire:key="nfshare-r-{{ $recipient['email'] }}">
                        <div class="flex-grow-1 text-break">
                            @if ($recipient['name'])
                                {{ $recipient['name'] }} <span class="text-body-secondary small">&lt;{{ $recipient['email'] }}&gt;</span>
                            @else
                                {{ $recipient['email'] }}
                            @endif
                        </div>
                        <div class="form-check form-switch mb-0" title="Mit Passwort schützen">
                            <input class="form-check-input" type="checkbox" role="switch" id="nfshare-pw-{{ $i }}"
                                wire:model="recipients.{{ $i }}.password">
                            <label class="form-check-label small" for="nfshare-pw-{{ $i }}">
                                <i class="bi bi-key" aria-hidden="true"></i><span class="d-none d-sm-inline"> Passwort</span>
                            </label>
                        </div>
                        <button type="button" class="btn btn-sm btn-link text-danger p-0" wire:click="removeRecipient({{ $i }})"
                            aria-label="{{ $recipient['email'] }} entfernen">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="input-group">
            <input type="text" class="form-control @error('addressInput') is-invalid @enderror"
                placeholder="E-Mail-Adresse, z. B. Erika Muster <erika@example.de>"
                wire:model="addressInput" wire:keydown.enter.prevent="addFromInput" autocomplete="off"
                aria-label="Empfänger hinzufügen">
            <button type="button" class="btn btn-outline-secondary" wire:click="addFromInput">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Hinzufügen
            </button>
            @error('addressInput')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        @error('recipients')
            <div class="text-danger small mt-1">{{ $message }}</div>
        @enderror

        @if ($this->suggestions)
            <div class="mt-2">
                @foreach ($this->suggestions as $label => $items)
                    <div class="small text-body-secondary mt-1">{{ $label }}</div>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach ($items as $item)
                            @php($added = collect($recipients)->contains('email', $item['email']))
                            <button type="button" class="btn btn-sm {{ $added ? 'btn-secondary' : 'btn-outline-secondary' }}"
                                wire:click="addRecipient(@js($item['email']), @js($item['name']))" @disabled($added)
                                title="{{ $item['email'] }}{{ $item['hint'] ? ' · '.$item['hint'] : '' }}">
                                <i class="bi bi-person-plus" aria-hidden="true"></i>
                                {{ $item['name'] ?: $item['email'] }}
                                @if ($item['hint'])
                                    <span class="opacity-75">· {{ $item['hint'] }}</span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ------------------------------------------------------------ Angaben --}}
        <h6 class="text-body-secondary text-uppercase small mt-4 mb-2">Angaben für den Empfänger</h6>

        <div class="mb-2">
            <label for="nfshare-subject" class="form-label small mb-1">Betreff</label>
            <input id="nfshare-subject" type="text" class="form-control @error('subject') is-invalid @enderror"
                wire:model="subject" maxlength="200" required>
            @error('subject')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            <div class="form-text">Sieht der Empfänger erst nach der Passworteingabe. Bei S/MIME-Mails steht der Betreff unverschlüsselt im Kopf – nichts Vertrauliches eintragen.</div>
        </div>

        <div class="mb-2">
            <label for="nfshare-message" class="form-label small mb-1">Hinweis auf der Abrufseite <span class="text-body-secondary">(optional)</span></label>
            <textarea id="nfshare-message" class="form-control" rows="2" wire:model="message" maxlength="5000"></textarea>
        </div>

        <div class="row g-2">
            <div class="col-sm-4">
                <label for="nfshare-expires" class="form-label small mb-1">Abrufbar bis</label>
                <input id="nfshare-expires" type="date" class="form-control @error('expiresAt') is-invalid @enderror"
                    wire:model="expiresAt" min="{{ now()->addDay()->toDateString() }}" max="{{ now()->addDays(90)->toDateString() }}">
                @error('expiresAt')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-sm-4">
                <label for="nfshare-max" class="form-label small mb-1">Abrufe je Datei</label>
                <input id="nfshare-max" type="number" class="form-control @error('maxDownloads') is-invalid @enderror"
                    wire:model="maxDownloads" min="1" max="100">
                @error('maxDownloads')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-sm-4">
                <label for="nfshare-ref" class="form-label small mb-1">Referenz <span class="text-body-secondary">(intern)</span></label>
                <input id="nfshare-ref" type="text" class="form-control" wire:model="reference" maxlength="100"
                    placeholder="z. B. Aktenzeichen">
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <span class="me-auto small text-body-secondary">
            {{ count(array_filter($files, fn ($f) => $f['active'])) }} Datei(en) · {{ count($recipients) }} Empfänger
        </span>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="share">
            <span wire:loading.remove wire:target="share"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Bereitstellen</span>
            <span wire:loading wire:target="share"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Wird übertragen …</span>
        </button>
    </div>
</form>
