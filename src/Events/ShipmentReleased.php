<?php

namespace Nfgarching\Share\Events;

use Nfgarching\Share\Models\Shipment;

/**
 * Eine Sendung ist im Portal freigegeben. Die Anwendung kann hier z. B. einen
 * Aktenvermerk anlegen; der Kontext steht in $shipment->context.
 */
class ShipmentReleased
{
    public function __construct(public Shipment $shipment) {}
}
