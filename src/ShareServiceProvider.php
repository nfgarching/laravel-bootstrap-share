<?php

namespace Nfgarching\Share;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nfgarching\Core\Modals\ModalRegistry;
use Nfgarching\Share\Client\PortalClient;
use Nfgarching\Share\Console\CheckCommand;
use Nfgarching\Share\Console\SyncCommand;
use Nfgarching\Share\Livewire\ShareComposer;
use Nfgarching\Share\Livewire\ShareList;

class ShareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/share.php', 'nfshare');

        $this->app->bind(PortalClient::class, fn ($app) => PortalClient::fromConfig($app->make(HttpFactory::class)));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nfshare');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::component('nf-share-composer', ShareComposer::class);
        Livewire::component('nf-share-list', ShareList::class);

        // Modal nur, wenn das core-Package die Registry bereitstellt; sonst
        // <livewire:nf-share-composer /> selbst ins Layout setzen.
        if (class_exists(ModalRegistry::class)) {
            $this->app->make(ModalRegistry::class)->modal('nfshare::parts.modals', order: 86);
        }

        // Abgleich mit dem Portal alle fünf Minuten (abschaltbar mit NFSHARE_SCHEDULE=false)
        if (config('nfshare.sync.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command('share:sync')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, SyncCommand::class]);

            $this->publishes([
                __DIR__.'/../config/share.php' => config_path('nfshare.php'),
            ], 'nfshare-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/nfshare'),
            ], 'nfshare-views');
        }
    }
}
