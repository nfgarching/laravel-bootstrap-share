<?php

namespace Nfgarching\Share\Http;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Share\Models\Upload;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download einer abgeholten Datei aus der Ablage (nur angemeldete Benutzer).
 * Dateien in Quarantäne werden nicht ausgeliefert.
 */
class UploadDownloadController extends Controller
{
    public function show(Upload $upload): StreamedResponse
    {
        abort_unless(auth()->check(), 403);

        if ($gate = config('nfshare.gate')) {
            Gate::authorize($gate, [(array) ($upload->context ?? [])]);
        }

        abort_if($upload->status === Upload::STATUS_QUARANTINED || ! $upload->disk || ! $upload->path, 404);
        abort_unless(Storage::disk($upload->disk)->exists($upload->path), 404);

        return Storage::disk($upload->disk)->download($upload->path, $upload->name);
    }
}
