<?php

namespace Nfgarching\Share\Console;

use Illuminate\Console\Command;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Services\UploadFetcher;
use Nfgarching\Share\Support\KeyStore;

class FetchUploadsCommand extends Command
{
    protected $signature = 'share:fetch-uploads';

    protected $description = 'Holt eingegangene Uploads aus dem Download-Portal ab';

    public function handle(UploadFetcher $fetcher, KeyStore $keys): int
    {
        if (! $keys->exists()) {
            $this->warn('Kein Schlüsselpaar vorhanden (php artisan share:keygen). Nichts zu tun.');

            return self::SUCCESS;
        }

        try {
            $stats = $fetcher->run();
        } catch (PortalException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->info("Abgeholt: {$stats['fetched']}, Quarantäne: {$stats['quarantined']}, fehlgeschlagen: {$stats['failed']}");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
