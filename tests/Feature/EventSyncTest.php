<?php

namespace Nfgarching\Share\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Nfgarching\Share\Events\PortalEventReceived;
use Nfgarching\Share\Models\PortalEvent;
use Nfgarching\Share\Models\Shipment;
use Nfgarching\Share\Services\EventSync;
use Nfgarching\Share\Tests\TestCase;

class EventSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_update_local_shipment_and_are_not_counted_twice(): void
    {
        Event::fake([PortalEventReceived::class]);

        $shipmentId = (string) Str::uuid();
        $recipientId = (string) Str::uuid();

        $shipment = Shipment::create([
            'portal_id' => $shipmentId, 'subject' => 'Entwurf', 'status' => Shipment::STATUS_RELEASED,
            'max_downloads' => 5, 'expires_at' => now()->addDays(14), 'released_at' => now(),
        ]);
        $recipient = $shipment->recipients()->create(['portal_id' => $recipientId, 'email' => 'm@example.com']);

        $events = [
            ['id' => 11, 'type' => 'link.opened', 'shipment_id' => $shipmentId, 'recipient_id' => $recipientId, 'file_id' => null, 'ip' => '203.0.113.0', 'meta' => null, 'created_at' => '2026-11-10T08:00:00+00:00'],
            ['id' => 12, 'type' => 'file.downloaded', 'shipment_id' => $shipmentId, 'recipient_id' => $recipientId, 'file_id' => (string) Str::uuid(), 'ip' => '203.0.113.0', 'meta' => null, 'created_at' => '2026-11-10T08:01:00+00:00'],
            ['id' => 13, 'type' => 'link.locked', 'shipment_id' => $shipmentId, 'recipient_id' => $recipientId, 'file_id' => null, 'ip' => null, 'meta' => null, 'created_at' => '2026-11-10T08:02:00+00:00'],
        ];

        Http::fake([
            'https://portal.test/api/v1/events*' => Http::sequence()
                ->push(['data' => $events, 'next_cursor' => 13, 'has_more' => false])
                ->push(['data' => [], 'next_cursor' => 13, 'has_more' => false]),
        ]);

        $this->assertSame(3, app(EventSync::class)->run());
        $this->assertSame(0, app(EventSync::class)->run());

        $recipient->refresh();
        $this->assertNotNull($recipient->opened_at);
        $this->assertNotNull($recipient->first_downloaded_at);
        $this->assertSame(1, $recipient->downloads);
        $this->assertTrue($recipient->locked);
        $this->assertNotNull($recipient->notified_at);
        $this->assertSame(13, PortalEvent::cursor());

        // Zweiter Lauf fragt ab dem Cursor
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=13'));
        Event::assertDispatchedTimes(PortalEventReceived::class, 3);
    }
}
