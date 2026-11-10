<?php

namespace Nfgarching\Share\Console;

use Illuminate\Console\Command;
use Nfgarching\Share\Client\PortalException;
use Nfgarching\Share\Services\EventSync;

class SyncCommand extends Command
{
    protected $signature = 'share:sync';

    protected $description = 'Holt das Abrufprotokoll aus dem Download-Portal';

    public function handle(EventSync $sync): int
    {
        try {
            $count = $sync->run();
        } catch (PortalException $e) {
            $this->error($e->userMessage());

            return self::FAILURE;
        }

        $this->info("{$count} neue Protokolleinträge übernommen.");

        return self::SUCCESS;
    }
}
