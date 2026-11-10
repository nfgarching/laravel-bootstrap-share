<?php

namespace Nfgarching\Share\Tests;

use Livewire\LivewireServiceProvider;
use Nfgarching\Share\ShareServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, ShareServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('nfshare.url', 'https://portal.test');
        $app['config']->set('nfshare.token', 'pk_test');
        $app['config']->set('nfshare.secret', 'sk_test');
        $app['config']->set('nfshare.files.allowed_disks', ['local']);
        $app['config']->set('nfshare.files.allowed_prefixes', ['akten/']);
        $app['config']->set('nfshare.sync.schedule', false);
    }
}
