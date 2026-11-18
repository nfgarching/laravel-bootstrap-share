<?php

namespace Nfgarching\Share\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nfgarching\Share\Events\UploadReceived;
use Nfgarching\Share\Models\Upload;
use Nfgarching\Share\Models\UploadRequest;
use Nfgarching\Share\Services\UploadFetcher;
use Nfgarching\Share\Support\KeyStore;
use Nfgarching\Share\Support\StreamDecryptor;
use Nfgarching\Share\Tests\TestCase;

class UploadFetcherTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPath = sys_get_temp_dir().'/nfshare-key-'.Str::random(8);
        config([
            'nfshare.uploads.key_path' => $this->keyPath,
            'nfshare.uploads.files' => false,
            'nfshare.uploads.disk' => 'local',
            'nfshare.uploads.directory' => 'eingang',
        ]);

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        @unlink($this->keyPath);

        parent::tearDown();
    }

    public function test_fetch_decrypts_stores_and_acknowledges(): void
    {
        Event::fake([UploadReceived::class]);

        $keys = new KeyStore($this->keyPath);
        $publicKey = base64_decode($keys->generate());

        $request = UploadRequest::create([
            'portal_id' => (string) Str::uuid(), 'context' => ['akte' => 7], 'context_key' => 'akte:7',
            'subject' => 'Lohnabrechnungen', 'email' => 'm@example.com', 'status' => 'open',
        ]);

        $content = random_bytes(StreamDecryptor::CHUNK + 1000);
        [$encrypted, $sealed] = $this->encrypt($content, $publicKey);
        $id = (string) Str::uuid();

        Http::fake([
            'https://portal.test/api/v1/uploads?*' => Http::response(['data' => [[
                'id' => $id, 'status' => 'received', 'request_id' => $request->portal_id, 'reference' => null,
                'batch' => (string) Str::uuid(), 'via' => 'link', 'sender_email' => 'm@example.com', 'sender_name' => 'Erika',
                'subject' => 'Lohnabrechnungen', 'message' => 'Rest folgt', 'name' => 'Januar.pdf',
                'mime_type' => 'application/pdf', 'size' => strlen($content), 'sha256' => hash('sha256', $content),
                'sealed_key' => $sealed, 'created_at' => now()->toIso8601String(),
            ]]]),
            "https://portal.test/api/v1/uploads/{$id}/content" => Http::response($encrypted),
            "https://portal.test/api/v1/uploads/{$id}/ack" => Http::response(['data' => ['id' => $id, 'status' => 'fetched']]),
        ]);

        $stats = app(UploadFetcher::class)->run();

        $this->assertSame(['fetched' => 1, 'quarantined' => 0, 'failed' => 0], $stats);

        $upload = Upload::firstOrFail();
        $this->assertSame(Upload::STATUS_STORED, $upload->status);
        $this->assertSame('akte:7', $upload->context_key);
        $this->assertSame($request->id, $upload->share_upload_request_id);
        $this->assertSame($content, Storage::disk('local')->get($upload->path));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), "/uploads/{$id}/ack"));
        Event::assertDispatched(UploadReceived::class);
    }

    public function test_wrong_checksum_is_not_acknowledged(): void
    {
        $keys = new KeyStore($this->keyPath);
        $publicKey = base64_decode($keys->generate());

        [$encrypted, $sealed] = $this->encrypt('Inhalt', $publicKey);
        $id = (string) Str::uuid();

        Http::fake([
            'https://portal.test/api/v1/uploads?*' => Http::response(['data' => [[
                'id' => $id, 'batch' => (string) Str::uuid(), 'sender_email' => 'm@example.com',
                'name' => 'a.pdf', 'size' => 6, 'sha256' => hash('sha256', 'anders'), 'sealed_key' => $sealed,
            ]]]),
            "https://portal.test/api/v1/uploads/{$id}/content" => Http::response($encrypted),
        ]);

        $this->assertSame(1, app(UploadFetcher::class)->run()['failed']);
        $this->assertSame(0, Upload::count());
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/ack'));
    }

    /** Wie der Vault des Portals: secretstream mit 64-KB-Blöcken, Schlüssel versiegelt. */
    private function encrypt(string $content, string $publicKey): array
    {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        [$state, $out] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $chunks = str_split($content, StreamDecryptor::CHUNK) ?: [''];

        foreach ($chunks as $i => $chunk) {
            $out .= sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $i === count($chunks) - 1
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
        }

        return [$out, base64_encode(sodium_crypto_box_seal($key, $publicKey))];
    }
}
