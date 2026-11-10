<?php

namespace Nfgarching\Share\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Prüft Dateien, die beim Öffnen des Modals übergeben werden.
 *
 * Das Öffnen löst der Browser aus; deshalb nur Dateien aus den in
 * config('nfshare.files') freigegebenen Disks und Verzeichnissen.
 * Gleiche Regeln wie AttachmentGuard im Mailer.
 */
class FileGuard
{
    /**
     * @param  array{disk?: string, path?: string, name?: string}  $file
     * @return array{disk: string, path: string, name: string, size: int}|null
     */
    public static function check(array $file): ?array
    {
        $disk = (string) ($file['disk'] ?? 'local');
        $path = ltrim(str_replace('\\', '/', (string) ($file['path'] ?? '')), '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        if (! in_array($disk, (array) config('nfshare.files.allowed_disks', []), true)) {
            return null;
        }

        $prefixes = (array) config('nfshare.files.allowed_prefixes', []);

        if (! array_is_list($prefixes)) {
            $prefixes = (array) ($prefixes[$disk] ?? []);
        }

        if ($prefixes && ! collect($prefixes)->contains(
            fn ($prefix) => str_contains($prefix, '*') ? Str::is($prefix, $path) : Str::startsWith($path, $prefix)
        )) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return null;
            }

            $size = (int) $storage->size($path);
        } catch (Throwable) {
            return null;
        }

        $name = trim((string) ($file['name'] ?? '')) ?: basename($path);

        return [
            'disk' => $disk,
            'path' => $path,
            'name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 200),
            'size' => $size,
        ];
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
