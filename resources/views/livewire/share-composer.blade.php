{{--
    Modal „Über Portal senden“ (nf-share-composer), über die ModalRegistry in jedes Layout eingebunden.

    Nach der Freigabe bleibt das Modal offen und zeigt je Empfänger Link und Passwort.
    „E-Mail schreiben“ schließt dieses Modal und öffnet danach das Mailer-Modal
    (Bootstrap kann keine Modals im Modal); deshalb wartet Alpine auf hidden.bs.modal.
--}}
<div>
    <div class="modal fade" id="nfshareModal" tabindex="-1" aria-labelledby="nfshareTitle" aria-hidden="true"
        wire:ignore.self x-data="{ pendingMail: null, copied: null }"
        x-on:nfshare-show.window="bootstrap.Modal.getOrCreateInstance($el).show()"
        x-on:nfshare-hide.window="bootstrap.Modal.getOrCreateInstance($el).hide()"
        x-on:hidden-bs-modal.dot="if (pendingMail) { Livewire.dispatch('nfmailer-open', { options: pendingMail }); pendingMail = null }">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="nfshareTitle">
                        <i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>
                        {{ $result ? 'Bereitgestellt' : 'Über das Download-Portal bereitstellen' }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                @if ($result)
                    @include('nfshare::livewire.share-composer.result')
                @else
                    @include('nfshare::livewire.share-composer.form')
                @endif
            </div>
        </div>
    </div>
</div>
