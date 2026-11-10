<?php

namespace Nfgarching\Share\Console;

use Illuminate\Console\Command;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Client\PortalException;
use Throwable;

/**
 * Prüft Konfiguration, Erreichbarkeit und Anmeldung beim Portal, ohne etwas anzulegen.
 */
class CheckCommand extends Command
{
    protected $signature = 'share:check';

    protected $description = 'Prüft die Verbindung zum Download-Portal';

    public function handle(PortalClient $client): int
    {
        $ok = true;

        $this->line('Portal-URL:  '.(config('nfshare.url') ?: '<fehlt>'));
        $this->line('Token:       '.(config('nfshare.token') ? 'gesetzt' : '<fehlt>'));
        $this->line('Schlüssel:   '.(config('nfshare.secret') ? 'gesetzt' : '<fehlt>'));
        $this->line('TLS-Prüfung: '.var_export(config('nfshare.verify'), true));
        $this->line('Links speichern: '.(config('nfshare.store_secrets') ? 'ja (verschlüsselt)' : 'nein'));
        $this->newLine();

        if (! $client->isConfigured()) {
            $this->error('NFSHARE_URL, NFSHARE_TOKEN und NFSHARE_SECRET in der .env setzen.');

            return self::FAILURE;
        }

        if (str_starts_with((string) config('nfshare.url'), 'http://') && ! app()->environment('local', 'testing')) {
            $this->warn('Die Verbindung ist unverschlüsselt (http://). Nur innerhalb eines VPN-Tunnels vertretbar.');
        }

        if (filter_var(config('nfshare.verify'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
            $this->warn('Die TLS-Prüfung ist abgeschaltet (NFSHARE_VERIFY=false). Nur zum Testen.');
        }

        // Ein Abruf ab einem Cursor in ferner Zukunft liefert nichts, prüft aber
        // Erreichbarkeit, Anmeldung, Signatur und Uhrzeit.
        try {
            $client->events(PHP_INT_MAX >> 1, 1);
            $this->info('Verbindung und Anmeldung in Ordnung.');
        } catch (PortalException $e) {
            $ok = false;
            $this->error($e->userMessage());

            if ($e->status === 0) {
                $this->line('  '.$e->getMessage());
            }

            if ($e->status === 401) {
                $this->line('  Serverzeit hier: '.now()->toIso8601String().' – das Portal lässt höchstens 5 Minuten Abweichung zu.');
            }
        } catch (Throwable $e) {
            $ok = false;
            $this->error($e->getMessage());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
