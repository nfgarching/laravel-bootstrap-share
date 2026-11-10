<?php

namespace Nfgarching\Share\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Client\RequestSigner;
use Nfgarching\Share\Events\ShipmentReleased;
use Nfgarching\Share\Models\Shipment;
use Nfgarching\Share\Services\ShareService;
use Nfgarching\Share\Support\FileGuard;
use Nfgarching\Share\Tests\TestCase;

class ShareServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $shipmentId;

    private string $recipientId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::disk('local')->put('akten/1/vertrag.pdf', 'PDF-Inhalt');

        $this->shipmentId = (string) Str::uuid();
        $this->recipientId = (string) Str::uuid();
    }

    public function test_share_creates_uploads_and_releases(): void
    {
        Event::fake([ShipmentReleased::class]);
        $this->fakePortal();

        $result = app(ShareService::class)->share($this->payload(), userId: 7);

        $shipment = $result->shipment;
        $recipient = $shipment->recipients->first();

        $this->assertSame(Shipment::STATUS_RELEASED, $shipment->status);
        $this->assertSame($this->shipmentId, $shipment->portal_id);
        $this->assertSame('akte:1', $shipment->context_key);
        $this->assertSame($this->recipientId, $recipient->portal_id);
        $this->assertSame('https://download.test/d/abc', $result->links[$recipient->id]['url']);
        $this->assertSame('AAAA-BBBB-CCCC', $result->links[$recipient->id]['password']);

        // Gespeichert, aber verschlüsselt
        $this->assertSame('AAAA-BBBB-CCCC', $recipient->password);
        $raw = DB::table('share_recipients')->value('password');
        $this->assertStringNotContainsString('AAAA', $raw);

        Event::assertDispatched(ShipmentReleased::class);

        // Jede Anfrage korrekt signiert; der Upload mit der Prüfsumme der Datei
        Http::assertSentCount(3);
        Http::assertSent(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH).(($q = parse_url($request->url(), PHP_URL_QUERY)) ? '?'.$q : '');
            $hash = $request->header('X-Content-SHA256')[0];

            return $request->header('X-Signature')[0] === RequestSigner::sign('sk_test', $request->method(), $path, $request->header('X-Timestamp')[0], $hash)
                && $request->header('Authorization')[0] === 'Bearer pk_test';
        });
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/files')
            && $request->header('X-Content-SHA256')[0] === hash('sha256', 'PDF-Inhalt')
            && $request->header('X-Filename')[0] === rawurlencode('Vertrag Entwurf.pdf'));
    }

    public function test_failed_upload_marks_shipment_and_revokes_draft(): void
    {
        Http::fake([
            'https://portal.test/api/v1/shipments' => Http::response(['data' => $this->remoteShipment()], 201),
            "https://portal.test/api/v1/shipments/{$this->shipmentId}/files" => Http::response(['message' => 'Die Datei ist zu groß.'], 413),
            "https://portal.test/api/v1/shipments/{$this->shipmentId}" => Http::response(['data' => $this->remoteShipment('revoked')]),
        ]);

        try {
            app(ShareService::class)->share($this->payload());
            $this->fail('PortalException erwartet');
        } catch (PortalException $e) {
            $this->assertSame(413, $e->status);
        }

        $this->assertSame(Shipment::STATUS_FAILED, Shipment::first()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_file_guard_rejects_paths_outside_allowed_prefixes(): void
    {
        Storage::disk('local')->put('privat/geheim.pdf', 'x');

        $this->assertNotNull(FileGuard::check(['disk' => 'local', 'path' => 'akten/1/vertrag.pdf']));
        $this->assertNull(FileGuard::check(['disk' => 'local', 'path' => 'privat/geheim.pdf']));
        $this->assertNull(FileGuard::check(['disk' => 'local', 'path' => 'akten/../privat/geheim.pdf']));
        $this->assertNull(FileGuard::check(['disk' => 's3', 'path' => 'akten/1/vertrag.pdf']));
    }

    private function fakePortal(): void
    {
        Http::fake([
            'https://portal.test/api/v1/shipments' => Http::response(['data' => $this->remoteShipment()], 201),
            "https://portal.test/api/v1/shipments/{$this->shipmentId}/files" => Http::response(['data' => [
                'id' => (string) Str::uuid(), 'name' => 'Vertrag Entwurf.pdf', 'mime_type' => 'application/pdf',
                'size' => 10, 'sha256' => hash('sha256', 'PDF-Inhalt'),
            ]], 201),
            "https://portal.test/api/v1/shipments/{$this->shipmentId}/release" => Http::response(['data' => $this->remoteShipment('released', [
                'url' => 'https://download.test/d/abc', 'password' => 'AAAA-BBBB-CCCC',
            ])]),
        ]);
    }

    private function payload(): array
    {
        return [
            'context' => ['akte' => 1],
            'reference' => '26/0001',
            'subject' => 'Entwurf',
            'expires_at' => now()->addDays(14),
            'max_downloads' => 5,
            'recipients' => [['email' => 'Mandant@Example.com', 'name' => 'Erika Muster', 'password' => true]],
            'files' => [['disk' => 'local', 'path' => 'akten/1/vertrag.pdf', 'name' => 'Vertrag Entwurf.pdf']],
        ];
    }

    private function remoteShipment(string $status = 'draft', array $secrets = []): array
    {
        return [
            'id' => $this->shipmentId,
            'status' => $status,
            'max_downloads' => 5,
            'expires_at' => now()->addDays(14)->toIso8601String(),
            'released_at' => $status === 'released' ? now()->toIso8601String() : null,
            'recipients' => [[
                'id' => $this->recipientId, 'email' => 'mandant@example.com', 'name' => 'Erika Muster',
                'password_protected' => true, 'opened_at' => null, 'locked' => false, 'downloads' => 0, ...$secrets,
            ]],
        ];
    }
}
