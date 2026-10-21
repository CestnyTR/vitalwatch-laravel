<?php

namespace CestnyTR\VitalWatch\Tests;

use CestnyTR\VitalWatch\VitalWatchServiceProvider;
use CestnyTR\VitalWatch\Support\Packagist;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [VitalWatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('vitalwatch.url', 'https://vitalwatch.example.test');
        $app['config']->set('vitalwatch.key', 'test-project');
        $app['config']->set('vitalwatch.secret', str_repeat('s', 64));

        // Testler ağa çıkmaz: güncelleme denetimi varsayılan olarak çevrimdışı.
        $app->instance(Packagist::class, new class extends Packagist
        {
            protected function fetch(): ?string
            {
                return null;
            }
        });
    }
}
