<?php

namespace Nfgarching\Share\Events;

use Nfgarching\Share\Models\PortalEvent;
use Nfgarching\Share\Models\Recipient;
use Nfgarching\Share\Models\Shipment;

/**
 * Ein neuer Protokolleintrag aus dem Portal (share:sync).
 *
 *     Event::listen(PortalEventReceived::class, function ($e) {
 *         if ($e->event->type === 'file.downloaded') { … Aktenvermerk … }
 *     });
 *
 * $shipment und $recipient sind null, wenn die Sendung lokal nicht (mehr) existiert.
 */
class PortalEventReceived
{
    public function __construct(
        public PortalEvent $event,
        public ?Shipment $shipment,
        public ?Recipient $recipient,
    ) {}
}
