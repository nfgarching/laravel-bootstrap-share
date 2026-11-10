# nfgarching/share

Dateien über das Download-Portal ([laravel-bootstrap-download-app](https://github.com/nfgarching/laravel-bootstrap-download-app))
bereitstellen statt sie als E-Mail-Anhang zu verschicken.

- **Modal „Über Portal senden“** (Livewire 4, Bootstrap 5.3): Dateien, Empfänger, Frist, Abruflimit, Passwort je Empfänger
- Nach der Freigabe **Link und Passwort je Empfänger**; mit dem Mailer-Package ein Klick zur fertig vorbereiteten **E-Mail mit dem Link** (ohne Passwort)
- **Übersicht der Sendungen** je Akte oder gesamt: Status, geöffnet, Abrufe, Protokoll; Frist verlängern, widerrufen, gesperrte Empfänger freigeben
- **Abrufprotokoll** wird alle fünf Minuten aus dem Portal geholt; der Absender bekommt beim ersten Abruf eine E-Mail
- Laravel-Events für eigene Aktenvermerke
- Betreff, Adressen, Links und Passwörter liegen **verschlüsselt** in der Datenbank (`APP_KEY`)

> **Stand:** Der Code ist geschrieben, aber noch nicht in einer Laravel-Installation gelaufen.
> Geprüft sind die PHP-Syntax, die Signatur gegen die Testvektoren des Portals und der
> Adressparser. Vor dem Einsatz `composer test` im Package bzw. die Schritte unten ausführen.

---

## 1. Installation

`composer.json` der App (wie die übrigen nfgarching-Packages):

```json
"repositories": [
    { "type": "path", "url": "packages/nfgarching/share" }
],
"require": {
    "nfgarching/share": "*"
}
```

```bash
composer update nfgarching/share
php artisan vendor:publish --tag=nfshare-config     # optional: config/nfshare.php
php artisan migrate
```

Auf dem Portal einen API-Client anlegen (Werte werden nur einmal angezeigt):

```bash
docker compose exec app php artisan portal:client:create "Kanzlei intern"
```

`.env` der internen App:

```env
NFSHARE_URL=https://download.froehler-kollegen.de
NFSHARE_TOKEN=pk_…
NFSHARE_SECRET=sk_…
```

Verbindung prüfen:

```bash
php artisan share:check
```

`share:check` zeigt die Einstellungen und ruft das Protokoll des Portals ab. Bei
„Anmeldung abgelehnt“: Token und Schlüssel prüfen und die Uhrzeit beider Server –
das Portal lässt höchstens fünf Minuten Abweichung zu und jede Signatur nur einmal.

### Abgleich

Das Package trägt `share:sync` selbst in den Scheduler ein (alle fünf Minuten).
Es muss also nur der Scheduler der App laufen (`php artisan schedule:work` bzw.
der Cron-Eintrag). Abschalten mit `NFSHARE_SCHEDULE=false` und dann selbst in
`routes/console.php` eintragen.

## 2. Modal einbinden

Mit `nfgarching/core` geschieht das automatisch über die `ModalRegistry`.
Ohne Core einmal im Layout:

```blade
<livewire:nf-share-composer />
```

## 3. Modal öffnen

**Aus Blade (z. B. an einem Dokument der Akte):**

```blade
<button type="button" class="btn btn-outline-primary"
        x-data
        x-on:click="Livewire.dispatch('nfshare-open', { options: @js([
            'context'   => ['akte' => $akte->id],
            'reference' => $akte->aktenzeichen,
            'subject'   => 'Unterlagen zu Ihrer Angelegenheit',
            'files'     => [['disk' => 'local', 'path' => $dokument->path, 'name' => $dokument->name]],
        ]) })">
    <i class="bi bi-cloud-arrow-up"></i> Über Portal senden
</button>
```

**Aus einer Livewire-Komponente:**

```php
$this->dispatch('nfshare-open', options: [
    'context'  => ['akte' => $this->akte->id],
    'to'       => [['email' => $mandant->email, 'name' => $mandant->name]],
    'subject'  => 'Forderungsaufstellung',
    'files'    => [['disk' => 'local', 'path' => "akten/{$id}/forderung.pdf", 'name' => 'Forderungsaufstellung.pdf']],
]);
```

| Option          | Typ                                       | Bedeutung |
|-----------------|-------------------------------------------|-----------|
| `context`       | array                                     | Wird gespeichert (Übersicht je Akte) und an die Adressquellen des Mailers gereicht |
| `files`         | Liste aus `['disk','path','name']`        | Nur Disks/Pfade aus `config('nfshare.files')` |
| `to`            | Liste aus `"a@b.de"`, `"Name <a@b.de>"` oder `['email','name']` | Vorbelegung |
| `subject`       | string                                    | Betreff; sieht der Empfänger erst nach der Passworteingabe |
| `message`       | string                                    | Hinweis auf der Abrufseite |
| `reference`     | string                                    | Intern, z. B. Aktenzeichen; steht auch im Portal |
| `expires_days`  | int                                       | Frist (Standard 14, höchstens 90) |
| `max_downloads` | int                                       | Abrufe je Empfänger und Datei (Standard 5) |
| `password`      | bool                                      | Passwortschutz vorbelegen (Standard: an) |

Nach der Freigabe feuert die Komponente `nfshare-sent` mit der lokalen Sendungs-ID.

**Empfänger:** Ist `nfgarching/mailer` installiert und ein `context` übergeben, zeigt das
Modal die Vorschläge der Mailer-Adressquellen (Beteiligte der Akte, Favoriten …) zum
Anklicken. Freie Eingabe geht immer, auch mehrere Adressen auf einmal.

**E-Mail:** Mit dem Mailer öffnet „E-Mail mit Link schreiben“ das Mailer-Modal mit
Empfänger, Betreff und Text (Vorlage in `nfshare.mail`). Das Passwort steht nie in der
E-Mail; es wird angezeigt und soll per Telefon oder SMS mitgeteilt werden. Ohne Mailer
zeigt das Modal den Text zum Kopieren.

## 4. Übersicht

```blade
{{-- in der Akte --}}
<livewire:nf-share-list :context="['akte' => $akte->id]" />
```

Gesamtübersicht: Route `nfshare.index` (`/nfshare`).

## 5. Im Code verwenden

```php
use Nfgarching\Share\Services\ShareService;

$result = app(ShareService::class)->share([
    'context'    => ['akte' => $akte->id],
    'subject'    => 'Entwurf Vereinbarung',
    'recipients' => [['email' => 'm@example.com', 'name' => 'Erika Muster', 'password' => true]],
    'files'      => [['disk' => 'local', 'path' => 'akten/1/entwurf.pdf', 'name' => 'Entwurf.pdf']],
], userId: auth()->id());

$result->links[$recipientId]['url'];
$result->links[$recipientId]['password'];
```

Dateien, die aus dem Browser kommen, vorher mit `FileGuard::check()` prüfen.

Weitere Methoden: `revoke($shipment)`, `extend($shipment, $datum)`, `setMaxDownloads()`,
`unlock($recipient)`, `refresh($shipment)`.

### Ereignisse

```php
use Nfgarching\Share\Events\PortalEventReceived;
use Nfgarching\Share\Events\ShipmentReleased;

Event::listen(ShipmentReleased::class, fn ($e) => /* Aktenvermerk: bereitgestellt */);

Event::listen(PortalEventReceived::class, function ($e) {
    if ($e->event->type === 'file.downloaded') {
        // $e->shipment->context, $e->recipient->email, $e->event->occurred_at
    }
});
```

Ereignisarten wie in der Portal-API (`link.opened`, `password.failed`, `link.locked`,
`file.downloaded`, `download.limit_reached`, `shipment.expired` …).

## 6. Sicherheit

- Die interne App ruft das Portal auf, nie umgekehrt. Jeder Aufruf ist mit Token,
  Zeitstempel und HMAC signiert; das Portal lässt jede Signatur nur einmal zu.
- Im Produktivbetrieb soll die API nur über einen WireGuard-Tunnel erreichbar sein.
  `NFSHARE_URL` zeigt dann auf die Tunnel-Adresse; die Links für die Empfänger
  liefert das Portal mit seiner öffentlichen Adresse.
- `NFSHARE_VERIFY=false` schaltet die TLS-Prüfung ab – nur zum Testen.
- Dateien aus dem Browser nur aus freigegebenen Disks/Verzeichnissen (`nfshare.files`).
- Mit `NFSHARE_STORE_SECRETS=false` werden Link und Passwort nicht gespeichert, sondern
  nur direkt nach der Freigabe angezeigt.
- Optional `nfshare.gate`: Gate-Name, wird mit dem Kontext geprüft
  (`Gate::define('share-files', fn ($user, array $context) => …)`).

## 7. Tests

```bash
composer install
composer test
```

## Lizenz

MIT
