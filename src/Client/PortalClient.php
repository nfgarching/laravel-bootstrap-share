<?php

namespace Nfgarching\Share\Client;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * HTTP-Client für die Portal-API (/api/v1).
 *
 * Jeder Aufruf wird mit Zeitstempel und HMAC signiert. Das Portal lässt jede
 * Signatur nur einmal zu; eine Wiederholung braucht daher einen neuen
 * Zeitstempel und wird hier nicht automatisch ausgeführt. Gegen doppelte
 * Sendungen nach einer Zeitüberschreitung schützt der Idempotency-Key.
 */
class PortalClient
{
    public const PREFIX = '/api/v1';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly ?string $secret,
        private readonly bool|string $verify = true,
        private readonly int $timeout = 30,
        private readonly int $uploadTimeout = 600,
    ) {}

    public static function fromConfig(HttpFactory $http): self
    {
        $verify = config('nfshare.verify', true);

        // Aus der .env kommen Zeichenketten; "false"/"true" in bool umwandeln, sonst Pfad.
        if (is_string($verify) && in_array(strtolower($verify), ['true', 'false', '1', '0', ''], true)) {
            $verify = filter_var($verify, FILTER_VALIDATE_BOOL);
        }

        return new self(
            $http,
            (string) config('nfshare.url'),
            config('nfshare.token'),
            config('nfshare.secret'),
            $verify,
            (int) config('nfshare.timeout', 30),
            (int) config('nfshare.upload_timeout', 600),
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && filled($this->token) && filled($this->secret);
    }

    // ------------------------------------------------------------------ Sendungen

    /**
     * @param  array{reference?: ?string, subject: string, message?: ?string, expires_at?: ?string, max_downloads?: ?int, recipients: list<array{email: string, name?: ?string, password?: bool, require_login?: bool}>}  $payload
     * @return array<string, mixed> Sendungsobjekt
     */
    public function createShipment(array $payload, ?string $idempotencyKey = null): array
    {
        return $this->send('POST', '/shipments', json: $payload, headers: array_filter([
            'Idempotency-Key' => $idempotencyKey,
        ]))->json('data');
    }

    /**
     * Lädt eine Datei hoch. $stream wird vollständig gelesen; $sha256 ist die
     * Prüfsumme des Inhalts (vorher berechnet), das Portal vergleicht sie.
     *
     * @param  resource  $stream
     * @return array{id: string, name: string, mime_type: string, size: int, sha256: string}
     */
    public function uploadFile(string $shipmentId, $stream, string $name, string $sha256, int $size, ?string $mimeType = null): array
    {
        return $this->send(
            'POST',
            '/shipments/'.$this->id($shipmentId).'/files',
            stream: $stream,
            contentHash: $sha256,
            headers: array_filter([
                'X-Filename' => rawurlencode($name),
                'X-Mime-Type' => $mimeType,
                'Content-Length' => (string) $size,
            ]),
            timeout: $this->uploadTimeout,
        )->json('data');
    }

    /** @return array<string, mixed> Sendungsobjekt mit url/password je Empfänger (nur hier!) */
    public function release(string $shipmentId): array
    {
        return $this->send('POST', '/shipments/'.$this->id($shipmentId).'/release')->json('data');
    }

    /** @return array<string, mixed> */
    public function getShipment(string $shipmentId): array
    {
        return $this->send('GET', '/shipments/'.$this->id($shipmentId))->json('data');
    }

    /**
     * @param  array{expires_at?: string, max_downloads?: int, unlock_recipients?: list<string>}  $changes
     * @return array<string, mixed>
     */
    public function updateShipment(string $shipmentId, array $changes): array
    {
        return $this->send('PATCH', '/shipments/'.$this->id($shipmentId), json: $changes)->json('data');
    }

    /** @return array<string, mixed> */
    public function revokeShipment(string $shipmentId): array
    {
        return $this->send('DELETE', '/shipments/'.$this->id($shipmentId))->json('data');
    }

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: int, has_more: bool}
     */
    public function events(int $after = 0, int $limit = 100): array
    {
        return $this->send('GET', '/events', query: ['after' => $after, 'limit' => $limit])->json();
    }

    // ------------------------------------------------------------------ Transport

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $json
     * @param  resource|null  $stream
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        $stream = null,
        ?string $contentHash = null,
        array $headers = [],
        ?int $timeout = null,
    ): Response {
        if (! $this->isConfigured()) {
            throw new PortalException('Die Verbindung zum Portal ist nicht konfiguriert (NFSHARE_URL, NFSHARE_TOKEN, NFSHARE_SECRET).');
        }

        $method = strtoupper($method);

        // Pfad mit Query genau so signieren, wie er gesendet wird.
        $pathWithQuery = self::PREFIX.$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        if ($stream !== null) {
            $body = Utils::streamFor($stream);
            $contentHash = strtolower((string) $contentHash);
            $contentType = 'application/octet-stream';
        } else {
            $body = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $contentHash = hash('sha256', $body);
            $contentType = 'application/json';
        }

        $timestamp = (string) time();

        $request = $this->request($timeout)->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'X-Timestamp' => $timestamp,
            'X-Content-SHA256' => $contentHash,
            'X-Signature' => RequestSigner::sign((string) $this->secret, $method, $pathWithQuery, $timestamp, $contentHash),
            ...$headers,
        ]);

        if ($method !== 'GET') {
            $request = $request->withBody($body, $contentType);
        }

        try {
            $response = $request->send($method, $this->baseUrl.$pathWithQuery);
        } catch (ConnectionException $e) {
            throw new PortalException($e->getMessage(), 0, previous: $e);
        }

        if ($response->failed()) {
            throw new PortalException(
                (string) ($response->json('message') ?? $response->reason()),
                $response->status(),
                (array) ($response->json('errors') ?? []),
            );
        }

        return $response;
    }

    private function request(?int $timeout): PendingRequest
    {
        return $this->http
            ->timeout($timeout ?? $this->timeout)
            ->connectTimeout(10)
            ->withOptions(['verify' => $this->verify])
            ->withUserAgent('nfgarching-share/1.0');
    }

    private function id(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Ungültige Sendungs-ID.');
        }

        return $id;
    }
}
