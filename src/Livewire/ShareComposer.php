<?php

namespace Nfgarching\Share\Livewire;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Services\ShareService;
use Nfgarching\Share\Support\AddressParser;
use Nfgarching\Share\Support\FileGuard;
use Nfgarching\Share\Support\MailText;
use Throwable;

/**
 * Modal „Über Portal senden“.
 *
 * Öffnen:  Livewire.dispatch('nfshare-open', { options: {...} })
 *          $this->dispatch('nfshare-open', options: [...])
 * Danach:  Event 'nfshare-sent' mit { id } (lokale Sendungs-ID)
 *
 * Optionen:
 *   context        ['akte' => 123] – wird gespeichert, an die Adressquellen des Mailers gereicht
 *   files          Liste aus ['disk','path','name'] – geprüft durch FileGuard
 *   to             Liste aus "a@b.de", "Name <a@b.de>" oder ['email','name']
 *   subject        Betreff (sieht der Empfänger nach der Passworteingabe)
 *   message        Hinweistext für die Abrufseite
 *   reference      z. B. Aktenzeichen (nur intern und im Portal-Protokoll)
 *   expires_days   Frist in Tagen
 *   max_downloads  Abrufe je Empfänger und Datei
 *   password       Passwortschutz vorbelegen
 */
class ShareComposer extends Component
{
    #[Locked]
    public array $context = [];

    /** Geprüfte Dateien; 'active' = wird bereitgestellt */
    #[Locked]
    public array $files = [];

    /** @var list<array{email: string, name: ?string, password: bool}> */
    public array $recipients = [];

    public string $addressInput = '';

    public string $subject = '';

    public string $message = '';

    public string $reference = '';

    public string $expiresAt = '';

    public int $maxDownloads = 5;

    /** Passwortschutz für neu hinzugefügte Empfänger */
    public bool $password = true;

    public ?string $error = null;

    /** Ergebnis nach der Freigabe (Modal bleibt offen, damit Link und Passwort sichtbar sind) */
    #[Locked]
    public ?array $result = null;

    #[On('nfshare-open')]
    public function open(array $options = [], array $data = []): void
    {
        $options = $options ?: $data;

        $this->authorizeShare((array) ($options['context'] ?? []));

        $this->reset();
        $this->resetValidation();
        unset($this->suggestions);

        $this->context = (array) ($options['context'] ?? []);
        $this->subject = (string) ($options['subject'] ?? '');
        $this->message = (string) ($options['message'] ?? '');
        $this->reference = (string) ($options['reference'] ?? '');
        $this->password = (bool) ($options['password'] ?? config('nfshare.defaults.password', true));
        $this->maxDownloads = (int) ($options['max_downloads'] ?? config('nfshare.defaults.max_downloads', 5));

        $days = (int) ($options['expires_days'] ?? config('nfshare.defaults.expires_days', 14));
        $this->expiresAt = now()->addDays(max(1, min($days, 90)))->toDateString();

        $max = (int) config('nfshare.files.max_files', 20);
        $rejected = 0;

        foreach (array_slice((array) ($options['files'] ?? []), 0, $max) as $file) {
            $checked = is_array($file) ? FileGuard::check($file) : null;

            if ($checked === null) {
                $rejected++;

                continue;
            }

            $this->files[] = [...$checked, 'active' => true];
        }

        if ($rejected > 0) {
            $this->error = $rejected === 1
                ? 'Eine Datei ist nicht freigegeben oder nicht vorhanden und wurde nicht übernommen.'
                : "{$rejected} Dateien sind nicht freigegeben oder nicht vorhanden und wurden nicht übernommen.";
        }

        foreach ((array) ($options['to'] ?? []) as $value) {
            if ($address = AddressParser::one($value)) {
                $this->addRecipient($address['email'], $address['name']);
            }
        }

        $this->dispatch('nfshare-show');
    }

    // ------------------------------------------------------------------ Empfänger

    public function addFromInput(): void
    {
        $parsed = AddressParser::many($this->addressInput);

        foreach ($parsed['valid'] as $address) {
            $this->addRecipient($address['email'], $address['name']);
        }

        $this->addressInput = implode('; ', $parsed['invalid']);

        if ($parsed['invalid']) {
            $this->addError('addressInput', 'Keine gültige Adresse: '.implode(', ', $parsed['invalid']));
        } else {
            $this->resetErrorBag('addressInput');
        }
    }

    public function addRecipient(string $email, ?string $name = null): void
    {
        $address = AddressParser::one(['email' => $email, 'name' => $name]);

        if ($address === null || collect($this->recipients)->contains('email', $address['email'])) {
            return;
        }

        if (count($this->recipients) >= 50) {
            $this->addError('recipients', 'Höchstens 50 Empfänger je Sendung.');

            return;
        }

        $this->recipients[] = [...$address, 'password' => $this->password];
        $this->resetErrorBag('recipients');
    }

    public function removeRecipient(int $index): void
    {
        unset($this->recipients[$index]);
        $this->recipients = array_values($this->recipients);
    }

    public function toggleFile(int $index): void
    {
        if (isset($this->files[$index])) {
            $this->files[$index]['active'] = ! $this->files[$index]['active'];
        }
    }

    /**
     * Vorschläge aus den Adressquellen des Mailers (z. B. Beteiligte der Akte),
     * falls nfgarching/mailer installiert ist.
     *
     * @return array<string, list<array{email: string, name: ?string, hint: ?string}>>
     */
    #[Computed]
    public function suggestions(): array
    {
        $resolver = 'Nfgarching\\Mailer\\Sources\\RecipientResolver';

        if (! class_exists($resolver) || $this->context === []) {
            return [];
        }

        try {
            return app($resolver)->suggestions($this->context);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    #[Computed]
    public function mailerAvailable(): bool
    {
        return class_exists('Nfgarching\\Mailer\\Livewire\\MailComposer');
    }

    // ------------------------------------------------------------------ Freigabe

    public function share(ShareService $service): void
    {
        $this->authorizeShare($this->context);
        $this->error = null;

        // Noch nicht übernommene Eingabe im Adressfeld mitnehmen
        if (trim($this->addressInput) !== '') {
            $this->addFromInput();

            if ($this->getErrorBag()->has('addressInput')) {
                return;
            }
        }

        $this->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'expiresAt' => ['required', 'date', 'after:today', 'before_or_equal:'.now()->addDays(90)->toDateString()],
            'maxDownloads' => ['required', 'integer', 'min:1', 'max:100'],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*.email' => ['required', 'email:rfc', 'max:255'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.password' => ['boolean'],
        ], [
            'subject.required' => 'Bitte einen Betreff angeben.',
            'recipients.required' => 'Bitte mindestens einen Empfänger hinzufügen.',
            'expiresAt.after' => 'Die Frist muss in der Zukunft liegen.',
            'expiresAt.before_or_equal' => 'Höchstens 90 Tage.',
        ]);

        $files = array_values(array_filter($this->files, fn ($file) => $file['active']));

        if ($files === []) {
            $this->addError('files', 'Bitte mindestens eine Datei auswählen.');

            return;
        }

        // Dateien erneut prüfen: zwischen Öffnen und Freigabe könnten sie verschwunden sein
        $files = array_values(array_filter(array_map(fn ($file) => FileGuard::check($file), $files)));

        if ($files === []) {
            $this->addError('files', 'Die ausgewählten Dateien sind nicht mehr vorhanden.');

            return;
        }

        try {
            $result = $service->share([
                'context' => $this->context,
                'reference' => $this->reference ?: null,
                'subject' => $this->subject,
                'message' => $this->message ?: null,
                // Ende des gewählten Tages in der Zeitzone der Anwendung
                'expires_at' => Carbon::parse($this->expiresAt)->endOfDay(),
                'max_downloads' => $this->maxDownloads,
                'recipients' => $this->recipients,
                'files' => $files,
            ], auth()->id());
        } catch (PortalException $e) {
            report($e);
            $this->error = $e->userMessage();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->error = 'Die Freigabe ist fehlgeschlagen: '.$e->getMessage();

            return;
        }

        $shipment = $result->shipment;

        $this->result = [
            'id' => $shipment->id,
            'subject' => $shipment->subject,
            'expires' => $shipment->expires_at?->timezone(config('app.timezone'))->format('d.m.Y H:i'),
            'stored' => (bool) config('nfshare.store_secrets', true),
            'recipients' => $shipment->recipients->map(function ($recipient) use ($result, $shipment) {
                $link = $result->links[$recipient->id] ?? null;

                return [
                    'email' => $recipient->email,
                    'name' => $recipient->name,
                    'url' => $link['url'] ?? null,
                    'password' => $link['password'] ?? null,
                    'mail' => $link ? [
                        'context' => $this->context,
                        'to' => [['email' => $recipient->email, 'name' => $recipient->name]],
                        'links' => false,
                        ...MailText::for($shipment, $recipient, $link['url']),
                    ] : null,
                ];
            })->values()->all(),
        ];

        $this->dispatch('nfshare-sent', id: $shipment->id);
    }

    private function authorizeShare(array $context): void
    {
        abort_unless(auth()->check(), 403);

        if ($gate = config('nfshare.gate')) {
            Gate::authorize($gate, [$context]);
        }
    }

    public function render()
    {
        return view('nfshare::livewire.share-composer');
    }
}
