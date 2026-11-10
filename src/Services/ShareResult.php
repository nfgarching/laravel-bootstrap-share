<?php

namespace Nfgarching\Share\Services;

use Nfgarching\Share\Models\Shipment;

/**
 * Ergebnis einer Freigabe. $links enthält je Empfänger (lokale ID) Link und
 * Passwort im Klartext – nur hier, unabhängig von nfshare.store_secrets.
 */
final class ShareResult
{
    /**
     * @param  array<int, array{url: string, password: ?string}>  $links
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly array $links,
    ) {}
}
