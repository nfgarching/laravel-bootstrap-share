<?php

namespace Nfgarching\Share\Client;

use RuntimeException;
use Throwable;

/**
 * Fehler bei einem Aufruf der Portal-API. $status ist 0, wenn das Portal
 * nicht erreichbar war (Verbindung, DNS, TLS, Zeitüberschreitung).
 */
class PortalException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** Meldung für die Oberfläche, ohne technische Einzelheiten. */
    public function userMessage(): string
    {
        return match (true) {
            $this->status === 0 => 'Das Download-Portal ist nicht erreichbar. Bitte später erneut versuchen.',
            $this->status === 401 => 'Das Portal hat die Anmeldung abgelehnt. Bitte Token, Signaturschlüssel und Uhrzeit des Servers prüfen (php artisan share:check).',
            $this->status === 403 => 'Dem API-Client fehlt die nötige Berechtigung im Portal.',
            $this->status === 413 => 'Eine Datei ist zu groß für das Portal.',
            $this->status === 422 => 'Das Portal hat die Angaben abgelehnt: '.$this->getMessage(),
            $this->status === 429 => 'Zu viele Anfragen an das Portal. Bitte kurz warten.',
            default => 'Das Portal hat einen Fehler gemeldet ('.$this->status.'): '.$this->getMessage(),
        };
    }
}
