<?php

namespace Nfgarching\Share\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Protokolleintrag aus dem Portal (z. B. link.opened, file.downloaded).
 */
class PortalEvent extends Model
{
    public const LABELS = [
        'shipment.created' => 'Sendung angelegt',
        'file.added' => 'Datei übertragen',
        'shipment.released' => 'Freigegeben',
        'shipment.updated' => 'Geändert',
        'shipment.revoked' => 'Widerrufen',
        'shipment.expired' => 'Abgelaufen, Dateien gelöscht',
        'link.opened' => 'Link geöffnet',
        'password.ok' => 'Passwort eingegeben',
        'password.failed' => 'Falsches Passwort',
        'link.locked' => 'Link gesperrt (zu viele Fehlversuche)',
        'file.downloaded' => 'Datei heruntergeladen',
        'download.limit_reached' => 'Abruflimit erreicht',
    ];

    protected $table = 'share_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'portal_event_id' => 'integer',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'share_shipment_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public static function cursor(): int
    {
        return (int) self::max('portal_event_id');
    }
}
