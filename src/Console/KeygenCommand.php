<?php

namespace Nfgarching\Share\Console;

use Illuminate\Console\Command;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Support\KeyStore;
use Throwable;

/**
 * Schlüsselpaar für den Rückkanal erzeugen und den öffentlichen Schlüssel im Portal hinterlegen.
 */
class KeygenCommand extends Command
{
    protected $signature = 'share:keygen
        {--force : Vorhandenes Schlüsselpaar ersetzen (noch nicht abgeholte Uploads werden unlesbar!)}
        {--push : Nur den vorhandenen öffentlichen Schlüssel erneut im Portal hinterlegen}';

    protected $description = 'Erzeugt das Schlüsselpaar für Uploads und hinterlegt den öffentlichen Schlüssel im Portal';

    public function handle(KeyStore $keys, PortalClient $client): int
    {
        try {
            if (! $this->option('push')) {
                if ($keys->exists() && ! $this->option('force')) {
                    $this->info('Schlüsselpaar vorhanden (Fingerabdruck '.$keys->fingerprint().'). Mit --push erneut hinterlegen, mit --force ersetzen.');

                    return self::SUCCESS;
                }

                if ($keys->exists() && ! $this->confirm('Schlüsselpaar ersetzen? Noch nicht abgeholte Uploads lassen sich danach nicht mehr öffnen.')) {
                    return self::FAILURE;
                }

                $keys->generate((bool) $this->option('force'));
                $this->info('Schlüsselpaar erzeugt: '.config('nfshare.uploads.key_path'));
                $this->warn('Diese Datei in die Datensicherung aufnehmen. Ohne sie sind noch nicht abgeholte Uploads unlesbar.');
            }

            $result = $client->setUploadKey($keys->publicKey());
        } catch (PortalException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $local = $keys->fingerprint();
        $remote = $result['fingerprint'] ?? '?';

        $this->info("Öffentlicher Schlüssel im Portal hinterlegt (Fingerabdruck {$remote}).");

        if ($local !== $remote) {
            $this->error("Fingerabdruck stimmt nicht überein (lokal {$local}).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
